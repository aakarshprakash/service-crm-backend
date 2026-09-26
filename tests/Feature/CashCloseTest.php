<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * §5.15 daily cash close for offline collections by service agents.
 */
class CashCloseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $tech;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant] = $this->makeTenant();
        $this->tech = $this->makeUser($this->tenant, Role::Technician);
        $this->accountant = $this->makeUser($this->tenant, Role::Accountant);
    }

    /** Create an invoice of $total and record a collection by the technician. */
    private function collect(int $total, string $method, int $amount, ?Carbon $at = null): void
    {
        $this->inTenant($this->tenant, function () use ($total, $method, $amount, $at) {
            $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
            $job->visits()->create(['technician_id' => $this->tech->id, 'visit_date' => now()->toDateString(), 'service_type' => 'on_site',
                'start_time' => now(), 'end_time' => now(), 'status' => 'completed', 'labour_charge' => $total, 'total_charge' => $total]);
            $invoice = app(InvoiceService::class)->syncForJob($job);
            app(PaymentService::class)->recordOffline($invoice, $amount, $method, [
                'collected_by' => $this->tech->id, 'reference_no' => $method === 'cash' ? null : 'REF1', 'paid_at' => $at ?? now(),
            ]);
        });
    }

    private function today(): string
    {
        return now($this->tenant->timezone)->toDateString();
    }

    public function test_summary_splits_cash_cheque_and_digital(): void
    {
        $this->collect(100000, 'cash', 100000);
        $this->collect(50000, 'cheque', 50000);
        $this->collect(30000, 'upi', 30000);

        $this->actingAsUser($this->tech)->getJson('/api/v1/accounts/cash-summary?date='.$this->today())
            ->assertOk()
            ->assertJsonPath('data.cash', 100000)
            ->assertJsonPath('data.cheque', 50000)
            ->assertJsonPath('data.digital', 30000)
            ->assertJsonPath('data.expected_in_hand', 150000)
            ->assertJsonCount(3, 'data.payments');
    }

    public function test_mismatch_requires_remarks_and_submission_locks_entries(): void
    {
        $this->collect(100000, 'cash', 100000);
        $this->actingAsUser($this->tech);

        $this->postJson('/api/v1/accounts/cash-close', ['date' => $this->today(), 'amount_confirmed' => 90000])
            ->assertStatus(422)->assertJsonValidationErrors('remarks');
        $this->postJson('/api/v1/accounts/cash-close', ['date' => $this->today(), 'amount_confirmed' => 90000, 'remarks' => 'Gave change'])
            ->assertCreated()->assertJsonPath('data.expected_in_hand', 100000);
        $this->postJson('/api/v1/accounts/cash-close', ['date' => $this->today(), 'amount_confirmed' => 100000])
            ->assertStatus(422);

        $this->assertDatabaseMissing('payments', ['collected_by' => $this->tech->id, 'cash_close_id' => null]);
    }

    public function test_previous_day_must_be_closed_first_unless_admin_forces(): void
    {
        $yesterday = now($this->tenant->timezone)->subDay();
        $this->collect(40000, 'cash', 40000, $yesterday->copy()->setTime(12, 0)->utc());
        $this->collect(60000, 'cash', 60000);

        $this->actingAsUser($this->tech)->postJson('/api/v1/accounts/cash-close', ['date' => $this->today(), 'amount_confirmed' => 100000])
            ->assertStatus(422)->assertJsonValidationErrors('date');

        // FR-15.4: admin can force-close on behalf of an unavailable technician.
        $admin = User::where('tenant_id', $this->tenant->id)->where('role', 'admin')->first();
        $this->actingAsUser($admin)->postJson('/api/v1/accounts/cash-close', [
            'date' => $this->today(), 'amount_confirmed' => 100000, 'technician_id' => $this->tech->id,
        ])->assertCreated()->assertJsonPath('data.force_closed', true)->assertJsonPath('data.total_cash_collected', 100000);
    }

    public function test_verify_deposit_and_carry_forward(): void
    {
        $this->collect(100000, 'cash', 100000);
        $closeId = $this->actingAsUser($this->tech)
            ->postJson('/api/v1/accounts/cash-close', ['date' => $this->today(), 'amount_confirmed' => 100000])->json('data.id');

        $this->actingAsUser($this->accountant);
        // Discrepancy needs a remark (FR-15.3).
        $this->patchJson("/api/v1/accounts/cash-close/{$closeId}/verify", ['amount_verified' => 95000])
            ->assertStatus(422)->assertJsonValidationErrors('discrepancy_remarks');
        $this->patchJson("/api/v1/accounts/cash-close/{$closeId}/verify", ['amount_verified' => 95000, 'discrepancy_remarks' => 'Short 50'])
            ->assertOk()->assertJsonPath('data.discrepancy_amount', -5000)->assertJsonPath('data.closing_balance', 95000);

        // Bank deposit needs a reference; cannot exceed cash in hand.
        $this->postJson("/api/v1/accounts/cash-close/{$closeId}/deposit", ['amount' => 60000, 'deposited_to' => 'bank', 'deposit_date' => $this->today()])
            ->assertStatus(422)->assertJsonValidationErrors('reference_no');
        $this->postJson("/api/v1/accounts/cash-close/{$closeId}/deposit", ['amount' => 200000, 'deposited_to' => 'office', 'deposit_date' => $this->today()])
            ->assertStatus(422);
        $this->postJson("/api/v1/accounts/cash-close/{$closeId}/deposit", ['amount' => 60000, 'deposited_to' => 'office', 'deposit_date' => $this->today()])
            ->assertCreated();

        // FR-15.5: 350.00 stays with the technician and carries into the next close.
        $this->getJson('/api/v1/accounts/ledger?technician_id='.$this->tech->id)->assertOk()->assertJsonPath('data.cash_in_hand', 35000);

        Carbon::setTestNow(now()->addDay());
        $this->collect(20000, 'cash', 20000);
        $this->actingAsUser($this->tech)->getJson('/api/v1/accounts/cash-summary?date='.now($this->tenant->timezone)->toDateString())
            ->assertJsonPath('data.opening_balance', 35000)
            ->assertJsonPath('data.expected_in_hand', 55000);
        Carbon::setTestNow();
    }

    public function test_credit_sales_never_enter_cash_close(): void
    {
        $this->inTenant($this->tenant, function () {
            $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
            $job->visits()->create(['technician_id' => $this->tech->id, 'visit_date' => now()->toDateString(), 'service_type' => 'on_site',
                'start_time' => now(), 'end_time' => now(), 'status' => 'completed', 'labour_charge' => 80000, 'total_charge' => 80000, 'payment_method' => 'credit']);
            app(InvoiceService::class)->syncForJob($job);
        });

        $this->actingAsUser($this->tech)->getJson('/api/v1/accounts/cash-summary?date='.$this->today())
            ->assertJsonPath('data.expected_in_hand', 0)
            ->assertJsonPath('data.credit', 80000);
        $this->assertTrue($this->inTenant($this->tenant, fn () => Invoice::first()->is_credit));
    }

    public function test_technician_cannot_see_other_technicians_cash(): void
    {
        $other = $this->makeUser($this->tenant, Role::Technician);
        $this->collect(100000, 'cash', 100000);

        $this->actingAsUser($other)->getJson('/api/v1/accounts/cash-summary?technician_id='.$this->tech->id)
            ->assertOk()->assertJsonPath('data.technician.id', $other->id)->assertJsonPath('data.cash', 0);
    }
}
