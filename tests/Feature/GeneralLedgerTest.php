<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The ledger: double entry from bills, payments, income, expenses and contra entries, and its trial balance. */
class GeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tenant->forceFill(['gstin' => '32BGKPA0180J1ZT', 'state' => 'Kerala'])->save();
        $this->actingAsUser($this->admin);
    }

    private function today(): string
    {
        return now($this->tenant->timezone)->toDateString();
    }

    /** A month of everyday entries: a GST bill part paid in cash, other income by UPI, an expense, a bank deposit. */
    private function everydayEntries(): array
    {
        $this->putJson('/api/v1/books/opening', ['date' => now($this->tenant->timezone)->startOfMonth()->toDateString(), 'cash' => 500000, 'bank' => 1000000])->assertOk();

        // ₹1,180 incl. 18% GST; ₹800 paid in cash, ₹380 still owed.
        $bill = $this->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Asha', 'phone' => '9811122233'], 'is_gst' => true,
            'items' => [['type' => 'service', 'description' => 'Gas top-up', 'quantity' => 1, 'unit_price' => 118000]],
            'payment_method' => 'cash', 'amount_paid' => 80000,
        ])->assertCreated()->json('data');

        $scrap = $this->inTenant($this->tenant, fn () => IncomeCategory::where('name', 'Service charge (no bill)')->firstOrFail());
        $this->postJson('/api/v1/incomes', ['income_date' => $this->today(), 'income_category_id' => $scrap->id, 'amount' => 25000, 'payment_method' => 'upi', 'reference_no' => 'UPI1'])->assertCreated();

        $fuel = $this->inTenant($this->tenant, fn () => ExpenseCategory::where('name', 'Fuel & travel')->firstOrFail());
        $this->postJson('/api/v1/expenses', ['expense_date' => $this->today(), 'expense_category_id' => $fuel->id, 'amount' => 30000, 'payment_method' => 'cash', 'paid_to' => 'HP pump'])->assertCreated();

        $this->postJson('/api/v1/books/transfers', ['transfer_date' => $this->today(), 'direction' => 'cash_to_bank', 'amount' => 40000])->assertCreated();

        return ['bill' => $bill, 'income' => $scrap, 'fuel' => $fuel];
    }

    private function account(array $tb, string $code): array
    {
        return collect($tb['accounts'])->firstWhere('code', $code) ?? $this->fail("No account {$code}");
    }

    public function test_the_trial_balance_balances_and_posts_each_entry_to_both_sides(): void
    {
        ['bill' => $bill, 'income' => $income, 'fuel' => $fuel] = $this->everydayEntries();
        $tb = $this->getJson('/api/v1/books/accounts')->assertOk()->json('data');

        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);

        // Cash: 5,000 opening + 800 received − 300 fuel − 400 banked.
        $this->assertSame(510000, $this->account($tb, 'cash')['closing']);
        // Bank: 10,000 opening + 250 UPI + 400 deposited.
        $this->assertSame(1065000, $this->account($tb, 'bank')['closing']);
        // The customer still owes 380.
        $this->assertSame(38000, $this->account($tb, 'debtors')['closing']);
        // Sales before GST, and the GST owed (credits, so negative).
        $this->assertSame(-100000, $this->account($tb, 'sales_walkin')['closing']);
        $this->assertSame(-18000, $this->account($tb, 'gst')['closing']);
        $this->assertSame(-25000, $this->account($tb, "income:{$income->id}")['closing']);
        $this->assertSame(30000, $this->account($tb, "expense:{$fuel->id}")['closing']);
        $this->assertSame(-1500000, $this->account($tb, 'capital')['closing']);
        $this->assertSame(['income' => 125000, 'expenses' => 30000], $tb['profit']);
        $this->assertSame($bill['balance_amount'], $this->account($tb, 'debtors')['closing']);
    }

    public function test_cash_and_bank_agree_with_the_cash_book_and_bank_book(): void
    {
        $this->everydayEntries();
        foreach (['cash', 'bank'] as $book) {
            $old = $this->getJson("/api/v1/books/ledger?book={$book}")->assertOk()->json('data');
            $new = $this->getJson("/api/v1/books/accounts/ledger?account={$book}")->assertOk()->json('data');
            $this->assertSame($old['closing'], $new['closing'], "{$book} closing");
            $this->assertSame($old['total_in'], $new['total_debit'] - ($new['entries'][0]['type'] === 'opening' ? $new['entries'][0]['debit'] : 0), "{$book} in");
            $this->assertSame($old['total_out'], $new['total_credit'], "{$book} out");
        }
    }

    public function test_an_account_ledger_shows_the_other_side_and_a_running_balance(): void
    {
        ['fuel' => $fuel] = $this->everydayEntries();
        $cash = $this->getJson('/api/v1/books/accounts/ledger?account=cash')->assertOk()->json('data');
        $this->assertSame(0, $cash['opening']);
        // The bill payment's place among the day's entries follows the clock, so look entries up by type.
        $types = array_column($cash['entries'], 'type');
        $this->assertSame('opening', $types[0]);
        $this->assertEqualsCanonicalizing(['opening', 'receipt', 'contra', 'payment'], $types);
        $byType = array_column($cash['entries'], null, 'type');
        $this->assertSame('Capital (opening balances)', $byType['opening']['particulars']);
        $this->assertSame('Customers (receivable)', $byType['receipt']['particulars']);
        $this->assertSame('Bank / UPI', $byType['contra']['particulars']);
        $this->assertSame(80000, $byType['receipt']['debit']);
        $this->assertSame(40000, $byType['contra']['credit']);
        $this->assertSame(510000, end($cash['entries'])['balance']);

        $fuelLedger = $this->getJson("/api/v1/books/accounts/ledger?account=expense:{$fuel->id}")->assertOk()->json('data');
        $this->assertSame('Fuel & travel', $fuelLedger['account']['name']);
        $this->assertSame('Cash', $fuelLedger['entries'][0]['particulars']);
        $this->assertSame(30000, $fuelLedger['closing']);

        // Next month starts from this month's closing balance.
        $next = now($this->tenant->timezone)->addMonthNoOverflow()->startOfMonth();
        $later = $this->getJson('/api/v1/books/accounts/ledger?account=cash&from='.$next->toDateString().'&to='.$next->toDateString())->assertOk()->json('data');
        $this->assertSame(510000, $later['opening']);
        $this->assertSame([], $later['entries']);
    }

    public function test_each_customer_has_an_account(): void
    {
        ['bill' => $bill] = $this->everydayEntries();
        $rows = $this->getJson('/api/v1/books/accounts/customers')->assertOk()->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame(['name' => 'Asha', 'debit' => 118000, 'credit' => 80000, 'closing' => 38000], array_intersect_key($rows[0], array_flip(['name', 'debit', 'credit', 'closing'])));

        $ledger = $this->getJson("/api/v1/books/accounts/ledger?account=customer:{$rows[0]['id']}")->assertOk()->json('data');
        $this->assertSame('Asha', $ledger['account']['name']);
        $this->assertSame(['sales', 'receipt'], array_column($ledger['entries'], 'type'));
        $this->assertSame($bill['invoice_number'], $ledger['entries'][0]['number']);
        $this->assertSame(38000, $ledger['closing']);

        $this->getJson('/api/v1/books/accounts/ledger?account=customer:999999')->assertNotFound();
        $this->getJson('/api/v1/books/accounts/ledger?account=nonsense')->assertStatus(422);
    }

    public function test_bills_before_the_opening_date_become_what_customers_owed_then(): void
    {
        // A bill from last month, part paid, before the books were opened in Servon.
        $this->travelTo(now()->subMonthNoOverflow());
        $this->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Old', 'phone' => '9811100001'],
            'items' => [['type' => 'service', 'description' => 'Repair', 'quantity' => 1, 'unit_price' => 50000]],
            'payment_method' => 'cash', 'amount_paid' => 20000,
        ])->assertCreated();
        $this->travelBack();
        $this->putJson('/api/v1/books/opening', ['date' => now($this->tenant->timezone)->startOfMonth()->toDateString(), 'cash' => 0, 'bank' => 0])->assertOk();

        $tb = $this->getJson('/api/v1/books/accounts')->assertOk()->json('data');
        $this->assertSame(30000, $this->account($tb, 'debtors')['closing']);
        $this->assertSame(-30000, $this->account($tb, 'capital')['closing']);
        $this->assertSame($tb['totals']['closing_dr'], $tb['totals']['closing_cr']);
    }

    public function test_only_accounts_staff_see_the_ledger(): void
    {
        foreach ([Role::Coordinator, Role::Technician, Role::ServiceTracker] as $role) {
            $this->forgetGuards();
            $this->actingAsUser($this->makeUser($this->tenant, $role));
            $this->getJson('/api/v1/books/accounts')->assertForbidden();
            $this->getJson('/api/v1/books/accounts/ledger?account=cash')->assertForbidden();
        }
        $this->forgetGuards();
        $this->actingAsUser($this->makeUser($this->tenant, Role::Accountant));
        $this->getJson('/api/v1/books/accounts')->assertOk();
    }
}
