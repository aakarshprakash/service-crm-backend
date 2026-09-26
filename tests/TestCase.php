<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\ServiceJob;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected const PASSWORD = 'Secret@123';

    /** Headers that make Sanctum treat a request as coming from the SPA (cookie session). */
    protected array $spa = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'];

    protected function plan(array $features = []): SubscriptionPlan
    {
        return SubscriptionPlan::firstOrCreate(['code' => 'test-'.md5(json_encode($features))], [
            'name' => 'Test plan', 'price' => 100000, 'billing_cycle' => 'monthly', 'max_users' => 20, 'max_technicians' => 20,
            'features' => array_merge(['customer_portal' => true, 'sms' => true, 'whatsapp' => true, 'online_payments' => true], $features),
        ]);
    }

    /** @return array{tenant: Tenant, admin: User} */
    protected function makeTenant(string $slug = 'acme', array $features = []): array
    {
        return app(TenantProvisioningService::class)->provision([
            'company_name' => ucfirst($slug).' Services',
            'slug' => $slug,
            'name' => ucfirst($slug).' Admin',
            'email' => "admin@{$slug}.test",
            'password' => self::PASSWORD,
            'plan_id' => $this->plan($features)->id,
            'status' => 'active',
        ]);
    }

    protected function makeUser(Tenant $tenant, Role $role, array $attributes = []): User
    {
        $user = new User(array_merge([
            'name' => ucfirst($role->value).' '.uniqid(),
            'email' => $role->value.uniqid().'@'.$tenant->slug.'.test',
            'phone' => '9'.random_int(100000000, 999999999),
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => 'active',
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'),
        ], $attributes));
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    /** Run a callback inside a tenant's context (for arranging data). */
    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant->id, $callback);
    }

    protected function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * The test container is shared across requests within a test, so a guard keeps the
     * user it resolved. Real requests each get a fresh one - call this between requests
     * that are meant to be made by different users.
     */
    protected function forgetGuards(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    protected function makeCustomer(Tenant $tenant, array $attributes = []): Customer
    {
        return $this->inTenant($tenant, function () use ($attributes) {
            $customer = Customer::create(array_merge(['name' => 'Test Customer', 'phone' => '98'.random_int(10000000, 99999999)], $attributes));
            $customer->products()->create(['serial_no' => 'SN'.random_int(1000, 9999)]);

            return $customer;
        });
    }

    protected function makeJob(Tenant $tenant, Customer $customer, ?User $technician = null, array $attributes = []): ServiceJob
    {
        return $this->inTenant($tenant, fn () => ServiceJob::create(array_merge([
            'crm_call_id' => 'T-'.uniqid(),
            'customer_id' => $customer->id,
            'priority' => 'medium',
            'call_type' => 'crm_call',
            'status' => 'open',
            'assigned_technician_id' => $technician?->id,
            'branch_id' => $technician?->branch_id,
        ], $attributes)));
    }

    protected function makeItem(Tenant $tenant, float $stock = 10, array $attributes = []): InventoryItem
    {
        return $this->inTenant($tenant, function () use ($stock, $attributes) {
            $item = InventoryItem::create(array_merge([
                'code' => 'IT'.random_int(1000, 99999), 'name' => 'Capacitor', 'type' => 'spare', 'unit_of_measure' => 'nos',
                'unit_price' => 50000, 'reorder_level' => 2,
            ], $attributes));
            if ($stock > 0) {
                app(InventoryService::class)->stockIn(Branch::first()->id, $item, $stock, 30000);
            }

            return $item;
        });
    }
}
