<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
