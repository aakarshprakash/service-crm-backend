<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tutorial_mode_is_off_by_default_and_admin_can_turn_it_on_for_everyone(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician)->fresh();

        $this->actingAsUser($tech)->getJson('/api/v1/auth/me')->assertJsonPath('data.user.tenant.tutorial_mode', false);

        $this->forgetGuards();
        $this->actingAsUser($admin)->putJson('/api/v1/settings/preferences', ['tutorial_mode' => true])
            ->assertOk()->assertJsonPath('data.tutorial_mode', true);

        // Company-wide: every role gets the flag on their profile, not just the admin.
        $this->forgetGuards();
        $this->actingAsUser($tech->fresh())->getJson('/api/v1/auth/me')->assertJsonPath('data.user.tenant.tutorial_mode', true);
    }

    public function test_admin_can_save_own_smtp_account_without_the_password_ever_coming_back_and_send_a_test_email(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $body = ['host' => 'Mail.Example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'bot@example.com', 'password' => 's3cret!', 'from_address' => 'bot@example.com', 'from_name' => 'Acme'];

        $this->actingAsUser($admin)->putJson('/api/v1/settings/email', $body)
            ->assertOk()->assertJsonPath('data.own_account', true)->assertJsonPath('data.host', 'mail.example.com')->assertJsonPath('data.has_password', true);
        $this->forgetGuards();
        $shown = $this->actingAsUser($admin)->getJson('/api/v1/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('s3cret!', $shown);
        $this->assertSame('s3cret!', $tenant->fresh()->mail_credentials['password']);
        // At rest it is encrypted, not plain JSON.
        $this->assertStringNotContainsString('s3cret!', (string) DB::table('tenants')->where('id', $tenant->id)->value('mail_credentials'));

        // A blank password on the next save keeps the stored one.
        $this->forgetGuards();
        $this->actingAsUser($admin)->putJson('/api/v1/settings/email', array_merge($body, ['password' => '']))->assertOk();
        $this->assertSame('s3cret!', $tenant->fresh()->mail_credentials['password']);

        // Remove falls back to the platform mailer, which a test email can still go through.
        $this->forgetGuards();
        $this->actingAsUser($admin)->putJson('/api/v1/settings/email', ['remove' => true])->assertOk()->assertJsonPath('data.own_account', false);
        $this->assertNull($tenant->fresh()->mail_credentials);
        $this->forgetGuards();
        $this->actingAsUser($admin)->postJson('/api/v1/settings/email/test', ['to' => 'me@example.com'])
            ->assertOk()->assertJsonPath('message', 'Test email sent to me@example.com using the platform mail account.');
    }

    public function test_email_settings_need_the_settings_permission(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician)->fresh();

        $this->actingAsUser($tech)->putJson('/api/v1/settings/email', ['remove' => true])->assertForbidden();
        $this->forgetGuards();
        $this->actingAsUser($tech->fresh())->postJson('/api/v1/settings/email/test', ['to' => 'me@example.com'])->assertForbidden();
    }
}
