<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\IncomeCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The office's end-of-day cash count, and the lock it puts on the office's entries. */
class OfficeDayCloseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accountant;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->accountant = $this->makeUser($this->tenant, Role::Accountant);
        $this->tech = $this->makeUser($this->tenant, Role::Technician);
    }

    private function today(int $offset = 0): string
    {
        return now($this->tenant->timezone)->addDays($offset)->toDateString();
    }

    private function as(User $user): static
    {
        return $this->forgetGuards()->actingAsUser($user);
    }

    private function fuel(): int
    {
        return $this->inTenant($this->tenant, fn () => ExpenseCategory::where('name', 'Fuel & travel')->value('id'));
    }

    private function expense(int $amount, string $date, string $method = 'cash'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/expenses', ['expense_date' => $date, 'expense_category_id' => $this->fuel(), 'amount' => $amount, 'payment_method' => $method]);
    }

    /** An invoice for a job, ready to be paid. */
    private function invoice(int $total): \App\Models\Invoice
    {
        return $this->inTenant($this->tenant, function () use ($total) {
            $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
            $job->visits()->create(['technician_id' => $this->tech->id, 'visit_date' => now()->toDateString(), 'service_type' => 'on_site',
                'start_time' => now(), 'end_time' => now(), 'status' => 'completed', 'labour_charge' => $total, 'total_charge' => $total]);

            return app(InvoiceService::class)->syncForJob($job);
        });
    }

    /** Money moving through the office today, plus money that never reaches the drawer. */
    private function busyDay(): void
    {
        $this->as($this->accountant);
        // Paid at the office counter: in the drawer.
        $this->postJson('/api/v1/invoices/'.$this->invoice(20000)->id.'/pay', ['amount' => 20000, 'method' => 'cash'])->assertCreated();
        // Paid by UPI at the office: in the bank, not the drawer.
        $this->postJson('/api/v1/invoices/'.$this->invoice(7000)->id.'/pay', ['amount' => 7000, 'method' => 'upi', 'reference_no' => 'UPI1'])->assertCreated();
        // Collected by a technician: with them until they hand it over.
        $this->inTenant($this->tenant, fn () => app(PaymentService::class)->recordOffline($this->invoice(100000), 100000, 'cash', ['collected_by' => $this->tech->id]));

        $this->expense(5000, $this->today())->assertCreated();
        $this->expense(9000, $this->today(), 'upi')->assertCreated();
        $category = $this->inTenant($this->tenant, fn () => IncomeCategory::value('id'));
        $this->postJson('/api/v1/incomes', ['income_date' => $this->today(), 'income_category_id' => $category, 'amount' => 3000, 'payment_method' => 'cash'])->assertCreated();
        $this->postJson('/api/v1/books/transfers', ['transfer_date' => $this->today(), 'direction' => 'cash_to_bank', 'amount' => 4000])->assertCreated();

        // The technician closes their day and hands ₹600 of their ₹1,000 to the office.
        $this->as($this->tech)->postJson('/api/v1/accounts/cash-close', ['date' => $this->today(), 'amount_confirmed' => 100000])->assertCreated();
        $closeId = $this->inTenant($this->tenant, fn () => \App\Models\CashClose::value('id'));
        $this->as($this->accountant)->patchJson("/api/v1/accounts/cash-close/{$closeId}/verify", ['amount_verified' => 100000])->assertOk();
        $this->postJson("/api/v1/accounts/cash-close/{$closeId}/deposit", ['amount' => 60000, 'deposited_to' => 'office', 'deposit_date' => $this->today()])->assertCreated();

        // A claim the technician paid from collected cash is a cash expense in the books, but never left the drawer.
        $this->inTenant($this->tenant, function () {
            $e = Expense::create(['expense_date' => $this->today(), 'expense_category_id' => $this->fuel(), 'amount' => 2500, 'payment_method' => 'cash', 'user_id' => $this->tech->id]);
            ExpenseClaim::create(['user_id' => $this->tech->id, 'expense_category_id' => $this->fuel(), 'claim_date' => $this->today(), 'amount' => 2500,
                'paid_from' => 'cash_in_hand', 'status' => 'approved', 'expense_id' => $e->id]);
        });
    }

    public function test_expected_cash_counts_only_money_through_the_office(): void
    {
        $this->busyDay();

        $this->getJson('/api/v1/books/day-close?date='.$this->today())->assertOk()
            ->assertJsonPath('data.can_close', true)
            ->assertJsonPath('data.needs_opening', true)
            ->assertJsonPath('data.expected_cash', null)
            ->assertJsonPath('data.flows.counter_receipts', 20000)
            ->assertJsonPath('data.flows.other_income', 3000)
            ->assertJsonPath('data.flows.from_technicians', 60000)
            ->assertJsonPath('data.flows.cash_expenses', 5000)
            ->assertJsonPath('data.flows.to_bank', 4000)
            ->assertJsonPath('data.flows.from_bank', 0)
            ->assertJsonPath('data.with_technicians', 37500); // ₹1,000 − ₹600 handed over − ₹25 spent

        // The first close needs the drawer's starting cash; then 100 + 200 + 30 + 600 − 50 − 40 = ₹840 expected.
        $this->postJson('/api/v1/books/day-close', ['date' => $this->today(), 'counted_cash' => 83000])->assertStatus(422)->assertJsonValidationErrors('opening_cash');
        $this->postJson('/api/v1/books/day-close', ['date' => $this->today(), 'counted_cash' => 83000, 'opening_cash' => 10000])
            ->assertStatus(422)->assertJsonValidationErrors('remarks');
        $this->postJson('/api/v1/books/day-close', ['date' => $this->today(), 'counted_cash' => 83000, 'opening_cash' => 10000, 'remarks' => 'Change given, not noted'])
            ->assertCreated()
            ->assertJsonPath('data.expected_cash', 84000)
            ->assertJsonPath('data.difference', -1000)
            ->assertJsonPath('data.reopenable', true);

        // The shortfall goes into the cash book, and the register shows the day closed.
        $cash = $this->getJson('/api/v1/books/ledger?book=cash&from='.$this->today().'&to='.$this->today())->assertOk()->json('data.entries');
        $this->assertContains(1000, array_column(array_filter($cash, fn ($e) => $e['type'] === 'adjustment'), 'out'));
        $this->getJson('/api/v1/books/register?from='.$this->today().'&to='.$this->today())->assertOk()
            ->assertJsonPath('data.closed_through', $this->today())
            ->assertJsonPath('data.days.0.locked', true)
            ->assertJsonPath('data.days.0.office_close.counted_cash', 83000)
            ->assertJsonPath('data.days.0.adjustments.0.cash_out', 1000);
    }

    public function test_a_closed_day_locks_the_office_entries(): void
    {
        $this->as($this->accountant);
        $this->expense(5000, $this->today(-1))->assertCreated();
        $expenseId = $this->inTenant($this->tenant, fn () => Expense::value('id'));
        $this->postJson('/api/v1/books/day-close', ['date' => $this->today(-1), 'counted_cash' => 5000, 'opening_cash' => 10000])->assertCreated();

        $this->expense(100, $this->today(-1))->assertStatus(422)->assertJsonValidationErrors('expense_date');
        $this->patchJson("/api/v1/expenses/{$expenseId}", ['amount' => 100])->assertStatus(422)->assertJsonValidationErrors('expense_date');
        $this->deleteJson("/api/v1/expenses/{$expenseId}")->assertStatus(422);
        $this->postJson('/api/v1/books/transfers', ['transfer_date' => $this->today(-2), 'direction' => 'bank_to_cash', 'amount' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('transfer_date');
        $this->postJson('/api/v1/invoices/'.$this->invoice(100)->id.'/pay', ['amount' => 100, 'method' => 'cash', 'paid_at' => $this->today(-1).' 10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('paid_at');

        // Today is still open, and an earlier day can't be closed any more.
        $this->expense(100, $this->today())->assertCreated();
        $this->postJson('/api/v1/books/day-close', ['date' => $this->today(-3), 'counted_cash' => 0])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->getJson('/api/v1/books/day-close?date='.$this->today(-3))->assertOk()->assertJsonPath('data.can_close', false);
    }

    public function test_each_count_starts_from_the_last_and_only_an_admin_reopens_the_latest(): void
    {
        $this->as($this->accountant);
        $this->postJson('/api/v1/books/day-close', ['date' => $this->today(-1), 'counted_cash' => 9000, 'opening_cash' => 10000, 'remarks' => 'Short ₹10'])->assertCreated();
        $first = $this->inTenant($this->tenant, fn () => \App\Models\OfficeDayClose::value('id'));
        $this->expense(2000, $this->today())->assertCreated();

        // Starts from the ₹90 counted, not the ₹100 expected, so yesterday's shortfall isn't counted twice.
        $this->getJson('/api/v1/books/day-close?date='.$this->today())->assertOk()
            ->assertJsonPath('data.needs_opening', false)
            ->assertJsonPath('data.opening_cash', 9000)
            ->assertJsonPath('data.expected_cash', 7000);
        $res = $this->postJson('/api/v1/books/day-close', ['date' => $this->today(), 'counted_cash' => 7000])->assertCreated();
        $latest = $res->json('data.id');

        $this->deleteJson("/api/v1/books/day-close/{$latest}")->assertForbidden();
        $this->as($this->admin)->deleteJson("/api/v1/books/day-close/{$first}")->assertStatus(422);
        $this->deleteJson("/api/v1/books/day-close/{$latest}")->assertOk();

        // Today is open again; yesterday stays closed.
        $this->expense(100, $this->today())->assertCreated();
        $this->expense(100, $this->today(-1))->assertStatus(422);
    }

    public function test_technicians_cannot_close_the_office_day(): void
    {
        $this->as($this->tech)->postJson('/api/v1/books/day-close', ['date' => $this->today(), 'counted_cash' => 0, 'opening_cash' => 0])->assertForbidden();
        $this->getJson('/api/v1/books/day-close?date='.$this->today())->assertForbidden();
    }
}
