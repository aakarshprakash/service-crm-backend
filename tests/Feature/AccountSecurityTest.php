<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Team access control for production: sessions per device, remote sign-out, temporary
 * passwords that must be changed, and the same rules for the person themselves.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function phoneLogin(string $email, string $password, string $device = 'Pixel 8', ?string $app = null): string
    {
        $this->forgetGuards();

        return $this->postJson('/api/v1/auth/token', array_filter(['login' => $email, 'password' => $password, 'device_name' => $device, 'app' => $app]))
            ->assertOk()->json('data.token');
    }

    public function test_admin_sees_sessions_and_signs_a_technician_out_remotely(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::Admin);
        $tech = $this->makeUser($tenant, Role::Technician);
        $phone = $this->phoneLogin($tech->email, self::PASSWORD, 'Galaxy A15');
        $this->phoneLogin($tech->email, self::PASSWORD, 'Old phone');
        $this->inTenant($tenant, fn () => DeviceToken::create(['user_id' => $tech->id, 'token' => 'fcm-1', 'platform' => 'android', 'app' => 'technician']));

        $sessions = $this->forgetGuards()->actingAsUser($admin)->getJson("/api/v1/users/{$tech->id}/sessions")->assertOk()
            ->assertJsonCount(2, 'data.sessions')->assertJsonPath('data.sessions.0.app', 'technician')
            ->assertJsonCount(1, 'data.push_devices')->json('data.sessions');

        // Lost phone: sign out just that one.
        $old = collect($sessions)->firstWhere('device', 'Old phone');
        $this->deleteJson("/api/v1/users/{$tech->id}/sessions/{$old['id']}")->assertOk();
        $this->getJson("/api/v1/users/{$tech->id}/sessions")->assertJsonCount(1, 'data.sessions')->assertJsonCount(1, 'data.push_devices');

        // Everywhere: the phone's token stops working and push stops.
        $this->postJson("/api/v1/users/{$tech->id}/sign-out")->assertOk()->assertJsonPath('data.signed_out', 1);
        $this->assertDatabaseMissing('device_tokens', ['user_id' => $tech->id]);
        $this->forgetGuards()->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.signed_out', 'model_id' => $tech->id]);
    }

    public function test_temporary_password_must_be_changed_before_anything_else(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::Admin);
        $tech = $this->makeUser($tenant, Role::Technician);
        $before = $this->phoneLogin($tech->email, self::PASSWORD);

        $temp = $this->forgetGuards()->actingAsUser($admin)->postJson("/api/v1/users/{$tech->id}/password", ['mode' => 'temporary'])
            ->assertOk()->json('data.temporary_password');
        $this->assertNotEmpty($temp);
        $this->forgetGuards()->withToken($before)->getJson('/api/v1/auth/me')->assertUnauthorized(); // old sessions ended

        $token = $this->phoneLogin($tech->email, $temp);
        $this->forgetGuards()->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.must_change_password', true);
        $this->getJson('/api/v1/jobs')->assertForbidden()->assertJsonPath('code', 'password_change_required');

        $this->putJson('/api/v1/auth/password', ['current_password' => $temp, 'password' => 'Fresh#Pass2026', 'password_confirmation' => 'Fresh#Pass2026'])->assertOk();
        $this->forgetGuards()->withToken($token)->getJson('/api/v1/jobs')->assertOk();
        $this->assertFalse($tech->fresh()->must_change_password);
        $this->assertNotNull($tech->fresh()->password_changed_at);
    }

    public function test_reset_link_by_email_and_permissions(): void
    {
        Notification::fake();
        ['tenant' => $tenant] = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::Admin);
        $coordinator = $this->makeUser($tenant, Role::Coordinator);
        $tech = $this->makeUser($tenant, Role::Technician);

        $this->actingAsUser($admin)->postJson("/api/v1/users/{$tech->id}/password", ['mode' => 'link'])->assertOk();
        Notification::assertSentTo($tech, \Illuminate\Auth\Notifications\ResetPassword::class);
        // Not on yourself; not for coordinators; not across companies.
        $this->postJson("/api/v1/users/{$admin->id}/password", ['mode' => 'temporary'])->assertStatus(422);
        $this->forgetGuards()->actingAsUser($coordinator)->postJson("/api/v1/users/{$tech->id}/sign-out")->assertForbidden();
        ['tenant' => $other] = $this->makeTenant('other');
        $otherAdmin = $this->makeUser($other, Role::Admin);
        $this->forgetGuards()->actingAsUser($otherAdmin)->getJson("/api/v1/users/{$tech->id}/sessions")->assertNotFound();
    }

    public function test_new_user_with_admin_password_and_password_edit_are_temporary(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $admin = $this->makeUser($tenant, Role::Admin);
        $id = $this->actingAsUser($admin)->postJson('/api/v1/users', [
            'name' => 'New Tech', 'email' => 'newtech@acme.test', 'role' => 'technician', 'password' => 'Start#Here2026',
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('users', ['id' => $id, 'must_change_password' => true]);

        $tech = $this->makeUser($tenant, Role::Technician);
        $token = $this->phoneLogin($tech->email, self::PASSWORD);
        $this->forgetGuards()->actingAsUser($admin)->patchJson("/api/v1/users/{$tech->id}", ['password' => 'Other#Pass2026'])->assertOk();
        $this->assertTrue($tech->fresh()->must_change_password);
        $this->forgetGuards()->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_people_manage_their_own_sessions(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $current = $this->phoneLogin($tech->email, self::PASSWORD, 'This phone');
        $other = $this->phoneLogin($tech->email, self::PASSWORD, 'Other phone');

        $list = $this->forgetGuards()->withToken($current)->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertTrue(collect($list)->firstWhere('device', 'This phone')['current']);

        $this->postJson('/api/v1/auth/sessions/sign-out-others')->assertOk()->assertJsonPath('data.signed_out', 1);
        $this->forgetGuards()->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->forgetGuards()->withToken($current)->getJson('/api/v1/auth/me')->assertOk();
    }
}
