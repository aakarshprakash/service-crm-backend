<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §13: automated tests asserting cross-tenant data is never returned.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_return_own_tenant_data(): void
    {
        ['tenant' => $a, 'admin' => $adminA] = $this->makeTenant('alpha');
        ['tenant' => $b] = $this->makeTenant('beta');
        $this->makeCustomer($a, ['name' => 'Alpha Customer']);
        $bCustomer = $this->makeCustomer($b, ['name' => 'Beta Customer']);
        $this->makeJob($b, $bCustomer);

        $this->actingAsUser($adminA);
        $this->getJson('/api/v1/customers')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Customer');
        $this->getJson('/api/v1/jobs')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/customers?search=Beta')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_direct_access_to_other_tenant_records_is_not_found(): void
    {
        ['tenant' => $a, 'admin' => $adminA] = $this->makeTenant('alpha');
        ['tenant' => $b] = $this->makeTenant('beta');
        $bCustomer = $this->makeCustomer($b);
        $bJob = $this->makeJob($b, $bCustomer);
        $bTech = $this->makeUser($b, Role::Technician);

        $this->actingAsUser($adminA);
        $this->getJson("/api/v1/customers/{$bCustomer->id}")->assertNotFound();
        $this->getJson("/api/v1/jobs/{$bJob->id}")->assertNotFound();
        $this->patchJson("/api/v1/customers/{$bCustomer->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->postJson("/api/v1/jobs/{$bJob->id}/cancel", ['reason' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/users/{$bTech->id}")->assertNotFound();

        $this->assertSame('Test Customer', Customer::withoutGlobalScopes()->find($bCustomer->id)->name);
    }

    public function test_foreign_keys_from_other_tenants_are_rejected(): void
    {
        ['tenant' => $a, 'admin' => $adminA] = $this->makeTenant('alpha');
        ['tenant' => $b] = $this->makeTenant('beta');
        $bCustomer = $this->makeCustomer($b);
        $bTech = $this->makeUser($b, Role::Technician);
        $aCustomer = $this->makeCustomer($a);

        $this->actingAsUser($adminA);
        $this->postJson('/api/v1/jobs', ['customer_id' => $bCustomer->id, 'priority' => 'low', 'call_type' => 'crm_call'])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->postJson('/api/v1/jobs', ['customer_id' => $aCustomer->id, 'priority' => 'low', 'call_type' => 'crm_call', 'assigned_technician_id' => $bTech->id])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_technician_id');
    }

    public function test_client_supplied_tenant_id_is_ignored(): void
    {
        ['tenant' => $a, 'admin' => $adminA] = $this->makeTenant('alpha');
        ['tenant' => $b] = $this->makeTenant('beta');

        $this->actingAsUser($adminA);
        $id = $this->postJson('/api/v1/customers', ['name' => 'Sneaky', 'phone' => '9876543210', 'tenant_id' => $b->id])
            ->assertCreated()->json('data.id');

        $this->assertSame($a->id, Customer::withoutGlobalScopes()->find($id)->tenant_id);
    }

    public function test_scope_fails_closed_without_tenant_context(): void
    {
        ['tenant' => $a] = $this->makeTenant('alpha');
        $this->makeCustomer($a);

        app(TenantContext::class)->reset();
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, ServiceJob::count());
        $this->assertSame(1, app(TenantContext::class)->withoutScope(fn () => Customer::count()));
    }

    public function test_super_admin_cannot_read_tenant_business_endpoints(): void
    {
        $this->seed();
        $super = User::where('role', 'super_admin')->first();

        $this->actingAsUser($super);
        $this->getJson('/api/v1/customers')->assertForbidden();
        $this->getJson('/api/v1/jobs')->assertForbidden();
        $this->getJson('/api/v1/admin/tenants')->assertOk();
    }
}
