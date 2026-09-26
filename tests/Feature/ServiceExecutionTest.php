<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\Branch;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §5.6 / §12.2 technician service execution, end to end.
 */
class ServiceExecutionTest extends TestCase
{
    use RefreshDatabase;

    /** Customer SMS is opt-in per tenant (plan feature + tenant toggle). */
    private function enableSms($tenant): void
    {
        $tenant->update(['settings' => array_replace_recursive($tenant->mergedSettings(), ['notifications' => ['sms' => true]])]);
    }

    public function test_coordinator_creates_and_assigns_job_with_notifications(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $this->enableSms($tenant);
        $coordinator = $this->makeUser($tenant, Role::Coordinator);
        $tech = $this->makeUser($tenant, Role::Technician);
        $customer = $this->makeCustomer($tenant);

        $this->actingAsUser($coordinator);
        $jobId = $this->postJson('/api/v1/jobs', [
            'customer_id' => $customer->id, 'priority' => 'high', 'call_type' => 'crm_call', 'complaint_details' => 'Not cooling',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

        $this->patchJson("/api/v1/jobs/{$jobId}/assign", ['technician_id' => $tech->id, 'scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.technician.id', $tech->id);

        $this->assertDatabaseHas('notification_logs', ['user_id' => $tech->id, 'type' => 'job_assigned', 'channel' => 'in_app']);
        $this->assertDatabaseHas('notification_logs', ['customer_id' => $customer->id, 'type' => 'technician_assigned', 'channel' => 'sms', 'status' => 'sent']);
        $this->getJson("/api/v1/jobs/{$jobId}")->assertOk()->assertJsonCount(2, 'data.status_history');
    }

    public function test_full_visit_flow_with_spares_images_invoice_and_cash_collection(): void
    {
        Storage::fake('private');
        ['tenant' => $tenant] = $this->makeTenant();
        $this->enableSms($tenant);
        $tech = $this->makeUser($tenant, Role::Technician);
        $customer = $this->makeCustomer($tenant);
        $job = $this->makeJob($tenant, $customer, $tech);
        $item = $this->makeItem($tenant, 5);
        $action = $this->inTenant($tenant, fn () => ActionTakenOption::first());

        $this->actingAsUser($tech);

        // FR-6.2: on-site visit needs location.
        $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'on_site'])
            ->assertStatus(422)->assertJsonValidationErrors('location');

        $visitId = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'on_site', 'lat' => 12.97, 'lng' => 77.59])
            ->assertCreated()->json('data.id');
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.status', 'in_progress');

        // Cannot start twice.
        $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'tele_call'])->assertStatus(422);

        // Images (FR-8.1).
        $this->postJson("/api/v1/visits/{$visitId}/images", ['type' => 'bill', 'image' => UploadedFile::fake()->image('bill.jpg', 800, 600)])
            ->assertCreated()->assertJsonStructure(['data' => ['url']]);
        $this->postJson("/api/v1/visits/{$visitId}/images", ['type' => 'bill', 'image' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')])
            ->assertStatus(422);

        // Spares (FR-7.5/7.6) – whole units only for "nos", stock decremented.
        $this->postJson("/api/v1/visits/{$visitId}/spares", ['item_id' => $item->id, 'quantity' => 1.5])->assertStatus(422);
        $this->postJson("/api/v1/visits/{$visitId}/spares", ['item_id' => $item->id, 'quantity' => 2])->assertCreated();
        $this->assertEquals(3.0, $this->inTenant($tenant, fn () => InventoryStock::where('item_id', $item->id)->value('quantity_available')));
        $this->postJson("/api/v1/visits/{$visitId}/spares", ['item_id' => $item->id, 'quantity' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');

        // FR-6.7: action taken is mandatory to complete – enforced server-side.
        $this->postJson("/api/v1/visits/{$visitId}/complete", ['status' => 'completed', 'service_summary' => 'Done', 'labour_charge' => 50000, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('action_taken_id');

        $result = $this->postJson("/api/v1/visits/{$visitId}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Replaced capacitor',
            'labour_charge' => 50000, 'payment_method' => 'cash', 'amount_collected' => 150000,
        ])->assertOk()->json('data');

        // FR-10.1: 500.00 labour + 2 × 500.00 spares = 1500.00
        $this->assertSame(100000, $result['visit']['spare_charge']);
        $this->assertSame(150000, $result['invoice']['total_amount']);
        $this->assertSame('paid', $result['invoice']['payment_status']);
        $this->assertNotEmpty($result['payment']['receipt_number']);
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.status', 'completed');

        // Closed visits are immutable.
        $this->postJson("/api/v1/visits/{$visitId}/spares", ['item_id' => $item->id, 'quantity' => 1])->assertStatus(422);
        $this->assertTrue(NotificationLog::where('type', 'job_completed')->exists());
    }

    public function test_pending_visit_on_credit_then_follow_up_visit(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $job = $this->makeJob($tenant, $this->makeCustomer($tenant), $tech);
        $this->actingAsUser($tech);

        $v1 = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'tele_call'])->json('data.id');
        $this->postJson("/api/v1/visits/{$v1}/complete", ['status' => 'pending', 'service_summary' => 'Part ordered', 'labour_charge' => 30000, 'payment_method' => 'credit'])
            ->assertOk()->assertJsonPath('data.invoice.is_credit', true)->assertJsonPath('data.invoice.balance_amount', 30000);
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.status', 'pending');

        // Pending → In progress with a second visit; invoice accumulates both visits.
        $v2 = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $action = $this->inTenant($tenant, fn () => ActionTakenOption::first());
        $this->postJson("/api/v1/visits/{$v2}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Fixed', 'labour_charge' => 20000,
            'payment_method' => 'upi', 'amount_collected' => 50000, 'payment_reference' => 'UPI123',
        ])->assertOk()->assertJsonPath('data.invoice.total_amount', 50000)->assertJsonPath('data.invoice.payment_status', 'paid');

        $this->assertSame(1, $this->inTenant($tenant, fn () => Invoice::count()));
    }

    public function test_collected_amount_cannot_exceed_invoice(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $job = $this->makeJob($tenant, $this->makeCustomer($tenant), $tech);
        $action = $this->inTenant($tenant, fn () => ActionTakenOption::first());
        $this->actingAsUser($tech);

        $v = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'tele_call'])->json('data.id');
        $this->postJson("/api/v1/visits/{$v}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'x', 'labour_charge' => 10000,
            'payment_method' => 'cash', 'amount_collected' => 20000,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Transaction rolled back: visit still open.
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.status', 'in_progress');
    }

    public function test_coordinator_can_cancel_and_reschedule_but_not_after_completion(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $coordinator = $this->makeUser($tenant, Role::Coordinator);
        $job = $this->makeJob($tenant, $this->makeCustomer($tenant));
        $done = $this->makeJob($tenant, $this->makeCustomer($tenant), null, ['status' => 'completed']);
        $this->actingAsUser($coordinator);

        $this->patchJson("/api/v1/jobs/{$job->id}/reschedule", ['scheduled_at' => now()->addDays(2)->toIso8601String(), 'reason' => 'Customer request'])->assertOk();
        $this->postJson("/api/v1/jobs/{$job->id}/cancel", [])->assertStatus(422);
        $this->postJson("/api/v1/jobs/{$job->id}/cancel", ['reason' => 'Duplicate'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/jobs/{$done->id}/cancel", ['reason' => 'x'])->assertStatus(422);
    }

    public function test_follow_up_job_is_linked_to_parent(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $coordinator = $this->makeUser($tenant, Role::Coordinator);
        $parent = $this->makeJob($tenant, $this->makeCustomer($tenant), null, ['status' => 'completed']);

        $this->actingAsUser($coordinator);
        $id = $this->postJson("/api/v1/jobs/{$parent->id}/follow-up", ['complaint_details' => '2nd service'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/jobs/{$id}")->assertJsonPath('data.parent.id', $parent->id);
    }

    public function test_search_by_serial_phone_and_call_id(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $customer = $this->makeCustomer($tenant, ['phone' => '9123456780']);
        $serial = $this->inTenant($tenant, fn () => $customer->products()->first()->serial_no);
        $job = $this->makeJob($tenant, $customer, null, ['crm_call_id' => 'SC-TEST-1', 'customer_product_id' => $this->inTenant($tenant, fn () => $customer->products()->first()->id)]);

        $this->actingAsUser($admin);
        foreach (['SC-TEST-1', '9123456780', $serial] as $term) {
            $this->getJson('/api/v1/jobs?search='.urlencode($term))->assertOk()->assertJsonPath('data.0.id', $job->id);
        }
        $this->getJson('/api/v1/jobs?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_low_stock_alert_is_raised_once(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $accountant = $this->makeUser($tenant, Role::Accountant);
        $item = $this->makeItem($tenant, 4, ['reorder_level' => 3]);
        $branch = $this->inTenant($tenant, fn () => Branch::first());

        $this->actingAsUser($accountant);
        $this->postJson('/api/v1/inventory/adjust', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/inventory/adjust', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity' => -1, 'reason' => 'Damaged'])->assertCreated();
        $this->postJson('/api/v1/inventory/adjust', ['branch_id' => $branch->id, 'item_id' => $item->id, 'quantity' => -1, 'reason' => 'Damaged'])->assertCreated();

        $this->assertSame(1, NotificationLog::where('user_id', $accountant->id)->where('type', 'low_stock')->count());
        $this->getJson('/api/v1/inventory/transactions?item_id='.$item->id)->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_stock_transfer_moves_quantity_between_branches(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $item = $this->makeItem($tenant, 10);
        [$from, $to] = $this->inTenant($tenant, fn () => [Branch::first(), Branch::create(['name' => 'Second'])]);

        $this->actingAsUser($admin);
        $this->postJson('/api/v1/inventory/transfer', ['from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'item_id' => $item->id, 'quantity' => 4])->assertCreated();
        $this->postJson('/api/v1/inventory/transfer', ['from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'item_id' => $item->id, 'quantity' => 40])->assertStatus(422);

        $stock = $this->inTenant($tenant, fn () => InventoryStock::where('item_id', $item->id)->pluck('quantity_available', 'branch_id'));
        $this->assertEquals(6, $stock[$from->id]);
        $this->assertEquals(4, $stock[$to->id]);
    }
}
