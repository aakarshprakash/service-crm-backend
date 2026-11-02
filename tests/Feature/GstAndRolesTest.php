<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GST tax invoices (GST-inclusive prices) and the Head Engineer / HR / Store / Service Tracker roles. */
class GstAndRolesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    private function registerForGst(): void
    {
        $this->actingAsUser($this->admin)->putJson('/api/v1/settings/company', [
            'name' => 'Acme Services', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR',
            'gstin' => '32AABCU9603R1ZM', 'pan' => 'AABCU9603R', 'state' => 'Kerala',
        ])->assertOk();
    }

    private function part(array $attrs = []): InventoryItem
    {
        return $this->inTenant($this->tenant, fn () => InventoryItem::create(array_merge([
            'code' => 'CAP-1', 'name' => 'Capacitor', 'type' => 'spare', 'unit_of_measure' => 'nos', 'unit_price' => 33333,
            'hsn_code' => '8532', 'gst_rate' => 28,
        ], $attrs)));
    }

    public function test_company_profile_saves_pan_and_rejects_bad_pan(): void
    {
        $this->registerForGst();
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.company.pan', 'AABCU9603R')->assertJsonPath('data.company.state', 'Kerala');
        $this->putJson('/api/v1/settings/company', ['name' => 'Acme', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR', 'pan' => 'ABC123'])
            ->assertStatus(422)->assertJsonValidationErrors('pan');
    }

    public function test_intra_state_walk_in_bill_splits_inclusive_price_into_cgst_and_sgst_exactly(): void
    {
        $this->registerForGst();
        $item = $this->part();
        $this->inTenant($this->tenant, fn () => \App\Models\InventoryStock::create(['item_id' => $item->id, 'branch_id' => $this->admin->branch_id, 'quantity_available' => 10]));

        $bill = $this->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Asha', 'phone' => '9811100001'],
            'items' => [
                ['type' => 'service', 'description' => 'Gas top-up', 'quantity' => 1, 'unit_price' => 118000],
                ['type' => 'part', 'item_id' => $item->id, 'quantity' => 3],
            ],
            'discount' => 10001,
            'payment_method' => 'cash', 'amount_paid' => 50000,
        ])->assertCreated()->json('data');

        $inv = $this->getJson("/api/v1/invoices/{$bill['id']}")->assertOk()->json('data');
        $total = 118000 + 99999 - 10001;
        $this->assertSame($total, $inv['total_amount']);           // GST-inclusive: total unchanged
        $this->assertTrue($inv['is_gst']);
        $this->assertSame(0, $inv['igst_amount']);
        $this->assertSame($total, $inv['taxable_amount'] + $inv['cgst_amount'] + $inv['sgst_amount']);
        $this->assertLessThanOrEqual(1, abs($inv['cgst_amount'] - $inv['sgst_amount']));
        $this->assertSame('Kerala', $inv['place_of_supply']);
        $this->assertSame($total - 50000, $inv['balance_amount']);  // payments unaffected by GST
        $lines = $inv['gst_breakup']['lines'];
        $this->assertSame(10001, array_sum(array_column($lines, 'discount')));
        $this->assertSame([18.0, 28.0], array_map('floatval', array_column($lines, 'gst_rate')));
        $this->assertSame('8532', $lines[1]['hsn']);

        foreach (['classic', 'modern'] as $design) {
            $this->putJson('/api/v1/settings/preferences', ['invoice_template' => $design])->assertOk();
            $this->get("/api/v1/invoices/{$bill['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->get("/api/v1/settings/invoice-preview?template={$design}")->assertOk();
        }
    }

    public function test_customer_in_another_state_is_charged_igst(): void
    {
        $this->registerForGst();
        $customer = $this->postJson('/api/v1/customers', ['name' => 'TN Traders', 'phone' => '9811100009', 'gstin' => '33AAACT1234A1Z5'])
            ->assertCreated()->json('data');
        $bill = $this->postJson('/api/v1/walk-in-bills', [
            'customer_id' => $customer['id'],
            'items' => [['type' => 'service', 'description' => 'AMC visit', 'quantity' => 1, 'unit_price' => 59001]],
            'payment_method' => 'credit',
        ])->assertCreated()->json('data');

        $inv = $this->getJson("/api/v1/invoices/{$bill['id']}")->json('data');
        $this->assertSame(0, $inv['cgst_amount'] + $inv['sgst_amount']);
        $this->assertSame(59001, $inv['taxable_amount'] + $inv['igst_amount']);
        $this->assertSame(50001, $inv['taxable_amount']); // 59001 / 1.18 = 50000.85 → 50001
        $this->assertSame('Tamil Nadu', $inv['place_of_supply']);
        $this->assertSame('33AAACT1234A1Z5', $inv['customer_gstin']);
    }

    public function test_profit_and_loss_excludes_gst_exactly_across_part_payments(): void
    {
        $this->registerForGst();
        $bill = $this->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Asha', 'phone' => '9811100001'],
            'items' => [['type' => 'service', 'description' => 'Repair', 'quantity' => 1, 'unit_price' => 100001]],
            'payment_method' => 'cash', 'amount_paid' => 33333,
        ])->assertCreated()->json('data');
        $inv = $this->getJson("/api/v1/invoices/{$bill['id']}")->json('data');
        $tax = $inv['cgst_amount'] + $inv['sgst_amount'];

        // Two more part payments settle it; the three GST shares must add up to the invoice GST exactly.
        $this->postJson("/api/v1/invoices/{$bill['id']}/pay", ['amount' => 33334, 'method' => 'cash'])->assertSuccessful();
        $this->postJson("/api/v1/invoices/{$bill['id']}/pay", ['amount' => 33334, 'method' => 'cash'])->assertSuccessful();

        $pl = $this->getJson('/api/v1/books/profit-loss')->assertOk()->json('data');
        $this->assertSame(100001, $pl['total_income']['current']);
        $this->assertSame($tax, $pl['gst']['current']);
        $this->assertSame(100001 - $tax, $pl['net']['current']);

        $report = $this->getJson('/api/v1/reports/gst-sales')->assertOk()->json('data');
        $this->assertSame($inv['taxable_amount'], $report['rows'][0]['taxable']);
        $daily = $this->getJson('/api/v1/reports/profit-loss')->assertOk()->json('data');
        $this->assertSame($tax, array_sum(array_column($daily['rows'], 'gst')));
        $this->assertSame(100001 - $tax, array_sum(array_column($daily['rows'], 'net')));
    }

    public function test_company_without_gstin_issues_plain_invoices(): void
    {
        $bill = $this->actingAsUser($this->admin)->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Asha', 'phone' => '9811100001'],
            'items' => [['type' => 'service', 'description' => 'Repair', 'quantity' => 1, 'unit_price' => 50000]],
            'discount' => 5000, 'payment_method' => 'credit',
        ])->assertCreated()->json('data');
        $inv = $this->getJson("/api/v1/invoices/{$bill['id']}")->json('data');
        $this->assertFalse($inv['is_gst']);
        $this->assertSame(45000, $inv['taxable_amount']);
        $this->assertSame(0, $inv['cgst_amount'] + $inv['sgst_amount'] + $inv['igst_amount']);
        $this->get("/api/v1/invoices/{$bill['id']}/pdf")->assertOk();
    }

    public function test_new_roles_get_their_own_access(): void
    {
        foreach (['head_engineer', 'hr_executive', 'store_keeper', 'service_tracker'] as $role) {
            $this->actingAsUser($this->admin)->postJson('/api/v1/users', [
                'name' => $role, 'email' => "$role@acme.test", 'role' => $role, 'password' => 'Secret@123',
            ])->assertCreated();
            $this->forgetGuards();
        }
        $he = $this->makeUser($this->tenant, Role::HeadEngineer)->fresh();
        $hr = $this->makeUser($this->tenant, Role::HrExecutive)->fresh();
        $store = $this->makeUser($this->tenant, Role::StoreKeeper)->fresh();
        $tracker = $this->makeUser($this->tenant, Role::ServiceTracker)->fresh();

        $abilities = fn (User $u) => $this->actingAsUser($u)->getJson('/api/v1/auth/me')->assertOk()->json('data.user.abilities');

        $a = $abilities($he);
        $this->forgetGuards();
        $this->assertContains('jobs.manage', $a);
        $this->assertContains('leave.approve', $a);
        $this->assertNotContains('accounts.view', $a);

        $a = $abilities($hr);
        $this->forgetGuards();
        $this->assertContains('payroll.manage', $a);
        $this->assertNotContains('jobs.view', $a);
        $this->assertNotContains('invoices.view', $a);

        $a = $abilities($store);
        $this->forgetGuards();
        $this->assertContains('inventory.manage', $a);
        $this->assertNotContains('customers.view', $a);

        $a = $abilities($tracker);
        $this->forgetGuards();
        $this->assertContains('jobs.manage', $a);
        $this->assertNotContains('inventory.manage', $a);

        // HR sees operations on the dashboard but not money; accounts stay closed.
        $this->actingAsUser($hr)->getJson('/api/v1/dashboard')->assertOk()->assertJsonMissingPath('data.revenue');
        $this->getJson('/api/v1/invoices')->assertForbidden();
        $this->forgetGuards();
        $this->actingAsUser($he)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.revenue.outstanding', 0)
            ->assertJsonPath('data.revenue.collected_today', 0)->assertJsonPath('data.today.completed', 0)->assertJsonStructure(['data' => ['today' => ['visits', 'logged']]]);
    }
}
