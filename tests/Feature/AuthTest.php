<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_login_starts_a_session_and_returns_profile(): void
    {
        ['admin' => $admin] = $this->makeTenant();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.tenant.slug', 'acme');

        $this->assertAuthenticatedAs($admin, 'web');
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.email', $admin->email);
    }

    public function test_wrong_password_is_rejected_with_generic_message(): void
    {
        ['admin' => $admin] = $this->makeTenant();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'nobody@x.test', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_login_is_rate_limited(): void
    {
        ['admin' => $admin] = $this->makeTenant();
        foreach (range(1, 5) as $i) {
            $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'bad'])->assertStatus(422);
        }
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_session_login_without_spa_origin_is_refused_cleanly(): void
    {
        ['admin' => $admin] = $this->makeTenant();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertStatus(400);
    }

    public function test_technician_gets_token_for_mobile_app(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician, ['phone' => '9000000001']);

        $token = $this->postJson('/api/v1/auth/token', ['login' => '9000000001', 'password' => self::PASSWORD, 'device_name' => 'Pixel 7'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/dashboard/technician')->assertOk()->assertJsonStructure(['data' => ['counts', 'today', 'cash_in_hand']]);
    }

    public function test_inactive_user_cannot_sign_in_and_existing_tokens_stop_working(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $token = $tech->createToken('phone')->plainTextToken;
        $tech->update(['status' => 'inactive']);

        $this->postJson('/api/v1/auth/token', ['login' => $tech->email, 'password' => self::PASSWORD, 'device_name' => 'x'])->assertStatus(422);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_suspended_tenant_is_blocked(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $tenant->update(['status' => 'suspended']);

        $this->actingAsUser($admin)->getJson('/api/v1/jobs')->assertForbidden()->assertJsonPath('code', 'tenant_unavailable');
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'This company account is suspended. Please contact support.');
    }

    public function test_expired_trial_is_blocked(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $tenant->update(['status' => 'trial', 'trial_ends_at' => now()->subDay()]);

        $this->actingAsUser($admin)->getJson('/api/v1/jobs')->assertForbidden();
    }

    public function test_tenant_self_signup_provisions_admin_and_defaults(): void
    {
        $this->plan();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/register', [
            'company_name' => 'Fresh Air Co', 'slug' => 'fresh-air', 'name' => 'Owner', 'email' => 'owner@fresh.test',
            'password' => 'Str0ng@Pass', 'password_confirmation' => 'Str0ng@Pass',
        ])->assertCreated()->assertJsonPath('data.user.tenant.status', 'trial');

        $owner = User::where('email', 'owner@fresh.test')->first();
        $this->assertSame(Role::Admin, $owner->role);
        $this->withHeaders($this->spa)->getJson('/api/v1/master/lookups')->assertOk()
            ->assertJsonPath('data.branches.0.name', 'Main Branch')
            ->assertJsonFragment(['name' => 'GAS CHARGING DONE']);
    }

    public function test_signup_rejects_weak_password_and_reserved_slug(): void
    {
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/register', [
            'company_name' => 'X', 'slug' => 'admin', 'name' => 'O', 'email' => 'o@x.test', 'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug', 'password']);
    }

    public function test_two_factor_login_flow(): void
    {
        ['admin' => $admin] = $this->makeTenant();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(32);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.two_factor', true);
        $this->assertGuest('web');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'code' => $google2fa->getCurrentOtp($secret)])
            ->assertOk()->assertJsonPath('data.user.two_factor_enabled', true);
    }

    public function test_customer_accounts_cannot_use_password_login(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $customerUser = $this->makeUser($tenant, Role::Customer);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $customerUser->email, 'password' => self::PASSWORD])
            ->assertStatus(422);
    }

    public function test_password_change_requires_current_password_and_strong_new_one(): void
    {
        ['admin' => $admin] = $this->makeTenant();
        $this->actingAsUser($admin);

        $this->putJson('/api/v1/auth/password', ['current_password' => 'nope', 'password' => 'N3w@Password', 'password_confirmation' => 'N3w@Password'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/auth/password', ['current_password' => self::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/auth/password', ['current_password' => self::PASSWORD, 'password' => 'N3w@Password', 'password_confirmation' => 'N3w@Password'])
            ->assertOk();
    }
}
