<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * §4 permission matrix enforced server-side (§13 "not just hidden in the UI").
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    public static function forbiddenMatrix(): array
    {
        return [
            'coordinator cannot manage users' => [Role::Coordinator, 'post', '/api/v1/users'],
            'coordinator cannot configure master data' => [Role::Coordinator, 'post', '/api/v1/master/brands'],
            'coordinator cannot verify cash' => [Role::Coordinator, 'patch', '/api/v1/accounts/cash-close/1/verify'],
            'coordinator cannot manage inventory' => [Role::Coordinator, 'post', '/api/v1/inventory/stock-in'],
            'coordinator cannot change settings' => [Role::Coordinator, 'get', '/api/v1/settings'],
            'accountant cannot create jobs' => [Role::Accountant, 'post', '/api/v1/jobs'],
            'accountant cannot manage users' => [Role::Accountant, 'post', '/api/v1/users'],
            'technician cannot create jobs' => [Role::Technician, 'post', '/api/v1/jobs'],
            'technician cannot list customers' => [Role::Technician, 'get', '/api/v1/customers'],
            'technician cannot view reports' => [Role::Technician, 'get', '/api/v1/reports'],
            'technician cannot verify cash' => [Role::Technician, 'patch', '/api/v1/accounts/cash-close/1/verify'],
            'technician cannot stock-in' => [Role::Technician, 'post', '/api/v1/inventory/stock-in'],
            'admin cannot execute visits' => [Role::Admin, 'post', '/api/v1/jobs/1/visits/start'],
            'tenant admin cannot reach super admin' => [Role::Admin, 'get', '/api/v1/admin/tenants'],
            'customer cannot list jobs' => [Role::Customer, 'get', '/api/v1/jobs'],
            'customer cannot view invoices list' => [Role::Customer, 'get', '/api/v1/invoices'],
        ];
    }

    #[DataProvider('forbiddenMatrix')]
    public function test_forbidden_actions(Role $role, string $method, string $uri): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $user = $this->makeUser($tenant, $role);

        $this->actingAsUser($user)->json($method, $uri, [])->assertForbidden();
    }

    public function test_coordinator_cannot_see_financial_reports_but_can_see_job_report(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $coordinator = $this->makeUser($tenant, Role::Coordinator);
        $this->actingAsUser($coordinator);

        $keys = collect($this->getJson('/api/v1/reports')->assertOk()->json('data'))->pluck('key');
        $this->assertContains('jobs', $keys);
        $this->assertNotContains('revenue', $keys);
        $this->getJson('/api/v1/reports/revenue')->assertForbidden();
        $this->getJson('/api/v1/reports/jobs')->assertOk();
    }

    public function test_technician_only_sees_own_jobs(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $mine = $this->makeUser($tenant, Role::Technician);
        $other = $this->makeUser($tenant, Role::Technician);
        $customer = $this->makeCustomer($tenant);
        $myJob = $this->makeJob($tenant, $customer, $mine);
        $otherJob = $this->makeJob($tenant, $customer, $other);

        $this->actingAsUser($mine);
        $this->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $myJob->id);
        $this->getJson("/api/v1/jobs/{$otherJob->id}")->assertNotFound();
        $this->postJson("/api/v1/jobs/{$otherJob->id}/visits/start", ['service_type' => 'tele_call'])->assertStatus(422);
    }

    public function test_technician_stock_view_hides_purchase_cost(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $this->makeItem($tenant, 5);
        $technician = $this->makeUser($tenant, Role::Technician);

        $this->actingAsUser($technician)->getJson('/api/v1/inventory/stock')->assertOk()
            ->assertJsonPath('data.0.quantity_available', 5)
            ->assertJsonMissingPath('data.0.avg_unit_cost')
            ->assertJsonMissingPath('data.0.stock_value');
        $office = $this->actingAsUser($admin)->getJson('/api/v1/inventory/stock')->assertOk()
            ->assertJsonPath('data.0.avg_unit_cost', 30000);
        $this->assertEquals(150000, $office->json('data.0.stock_value'));
    }

    public function test_admin_cannot_deactivate_self_or_change_own_role(): void
    {
        ['admin' => $admin] = $this->makeTenant();
        $this->actingAsUser($admin);

        $this->patchJson("/api/v1/users/{$admin->id}/status", ['status' => 'inactive'])->assertStatus(422);
        $this->patchJson("/api/v1/users/{$admin->id}", ['role' => 'technician'])->assertStatus(422);
    }

    public function test_plan_limits_are_enforced_when_adding_technicians(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $tenant->plan->update(['max_technicians' => 1]);
        $this->makeUser($tenant, Role::Technician);

        $this->actingAsUser($admin)->postJson('/api/v1/users', [
            'name' => 'Second Tech', 'email' => 'second@acme.test', 'role' => 'technician',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }
}
