<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CashClose;
use App\Models\CashDeposit;
use App\Models\IncomeCategory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Money received without an invoice, and how it and technicians' bank deposits reach every book. */
class ManualIncomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_without_an_invoice_reaches_the_books_register_day_book_and_profit(): void
    {
        $ctx = $this->makeTenant();
        $tenant = $ctx['tenant'];
        $tech = $this->makeUser($tenant, Role::Technician, ['name' => 'Thahir']);
        $today = CarbonImmutable::now($tenant->timezone)->toDateString();
        $category = $this->inTenant($tenant, fn () => IncomeCategory::where('name', 'Service charge (no bill)')->firstOrFail());

        $this->actingAsUser($ctx['admin']);
        $this->getJson('/api/v1/master/lookups')->assertJsonFragment(['name' => 'Scrap sale']);

        $this->postJson('/api/v1/incomes', [
            'income_date' => $today, 'income_category_id' => $category->id, 'amount' => 60000, 'payment_method' => 'cash',
            'received_from' => 'Balachandran', 'phone' => '9447143028', 'user_id' => $tech->id,
        ])->assertCreated();
        // A bank payment needs its reference.
        $this->postJson('/api/v1/incomes', ['income_date' => $today, 'income_category_id' => $category->id, 'amount' => 100000, 'payment_method' => 'upi', 'received_from' => 'Balachandran', 'user_id' => $tech->id])->assertCreated();
        $this->postJson('/api/v1/incomes', ['income_date' => $today, 'income_category_id' => $category->id, 'amount' => 100, 'payment_method' => 'cheque'])
            ->assertStatus(422)->assertJsonValidationErrors('reference_no');
        $this->postJson('/api/v1/incomes', ['income_date' => CarbonImmutable::now($tenant->timezone)->addDays(2)->toDateString(), 'income_category_id' => $category->id, 'amount' => 100, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('income_date');

        $this->getJson('/api/v1/incomes')->assertOk()->assertJsonPath('meta.total_amount', 160000);

        // Register: under "From Thahir", one line per payment, cash and bank columns.
        $day = $this->getJson("/api/v1/books/register?from={$today}&to={$today}")->json('data.days.0');
        $this->assertSame(['cash_in' => 60000, 'bank_in' => 100000, 'cash_out' => 0, 'bank_out' => 0], $day['totals']);
        $this->assertSame('Thahir', $day['receipts'][0]['party']);
        $this->assertSame(['Balachandran', '9447143028', 'Service charge (no bill)'], [$day['receipts'][0]['lines'][0]['customer'], $day['receipts'][0]['lines'][0]['phone'], $day['receipts'][0]['lines'][0]['category']]);

        // Cash book and bank book, day book, profit & loss.
        $this->getJson("/api/v1/books/ledger?book=cash&from={$today}&to={$today}")->assertJsonPath('data.total_in', 60000);
        $this->getJson("/api/v1/books/ledger?book=bank&from={$today}&to={$today}")->assertJsonPath('data.total_in', 100000);
        $this->assertSame(160000, array_sum(array_column($this->getJson("/api/v1/books/day-book?from={$today}&to={$today}")->json('data'), 'in')));
        $pl = $this->getJson("/api/v1/books/profit-loss?from={$today}&to={$today}")->json('data');
        $this->assertContains(['label' => 'Other income (no invoice)', 'current' => 160000, 'previous' => 0], $pl['income']);
        $this->assertSame(160000, $pl['total_income']['current']);

        // A category in use is kept, only switched off.
        $this->deleteJson("/api/v1/master/income-categories/{$category->id}")->assertOk();
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_a_technicians_bank_deposit_moves_cash_to_the_bank_book(): void
    {
        $ctx = $this->makeTenant();
        $tenant = $ctx['tenant'];
        $tech = $this->makeUser($tenant, Role::Technician, ['name' => 'Anwar']);
        $today = CarbonImmutable::now($tenant->timezone)->toDateString();
        $this->inTenant($tenant, function () use ($tech, $today) {
            $close = CashClose::create(['technician_id' => $tech->id, 'close_date' => $today, 'status' => 'verified', 'amount_confirmed' => 50000, 'closing_balance' => 50000]);
            CashDeposit::create(['cash_close_id' => $close->id, 'technician_id' => $tech->id, 'amount' => 30000, 'deposited_to' => 'bank', 'reference_no' => 'DEP-1', 'deposit_date' => $today]);
            CashDeposit::create(['cash_close_id' => $close->id, 'technician_id' => $tech->id, 'amount' => 20000, 'deposited_to' => 'office', 'deposit_date' => $today]);
        });

        $this->actingAsUser($ctx['admin']);
        $this->getJson("/api/v1/books/ledger?book=cash&from={$today}&to={$today}")->assertJsonPath('data.total_out', 30000);
        $this->getJson("/api/v1/books/ledger?book=bank&from={$today}&to={$today}")->assertJsonPath('data.total_in', 30000);
        $day = $this->getJson("/api/v1/books/register?from={$today}&to={$today}")->json('data.days.0');
        $this->assertSame('Cash deposited in bank by Anwar', $day['transfers'][0]['particulars']);
        $this->assertSame(['cash_in' => 0, 'bank_in' => 30000, 'cash_out' => 30000, 'bank_out' => 0], $day['totals']);
    }
}
