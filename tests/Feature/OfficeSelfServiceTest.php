<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /my/punch: what the Manager app's punch-in screen needs, for office staff and technicians alike.
 */
class OfficeSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private const RULES = ['geofence', 'branch', 'require_location', 'require_punch_in', 'auto_punch_out_at', 'shift'];

    public function test_my_punch_returns_status_and_the_punch_rules_for_staff(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $coordinator = $this->makeUser($tenant, Role::Coordinator);

        foreach ([$admin, $coordinator] as $staff) {
            $this->forgetGuards()->actingAsUser($staff)->getJson('/api/v1/my/punch')->assertOk()
                ->assertJsonPath('data.punch_status', 'out')
                ->assertJsonStructure(['data' => ['punch_status', 'punched_at', 'attendance' => self::RULES]]);
        }
    }

    public function test_my_punch_follows_the_users_own_punch_state(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->punchIn($this->makeUser($tenant, Role::Technician));

        $this->forgetGuards()->actingAsUser($tech)->getJson('/api/v1/my/punch')->assertOk()->assertJsonPath('data.punch_status', 'in');
    }

    public function test_the_technician_dashboard_still_carries_the_same_punch_rules(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->punchIn($this->makeUser($tenant, Role::Technician));

        $this->actingAsUser($tech)->getJson('/api/v1/dashboard/technician')->assertOk()
            ->assertJsonStructure(['data' => ['attendance' => self::RULES]]);
    }

    public function test_a_customer_cannot_use_my_punch(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $customerUser = $this->makeUser($tenant, Role::Customer);

        $this->actingAsUser($customerUser)->getJson('/api/v1/my/punch')->assertForbidden();
    }
}
