<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkInAndBooksTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private InventoryItem $part;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->part = $this->makeItem($this->tenant, 10, ['unit_price' => 50000]); // ₹500, 10 in stock
    }

    private function stock(): float
    {
        return (float) $this->inTenant($this->tenant, fn () => \App\Models\InventoryStock::where('item_id', $this->part->id)->sum('quantity_available'));
    }

    private function bill(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($this->admin)->postJson('/api/v1/walk-in-bills', array_merge([
            'customer' => ['name' => 'Walk-in Ravi', 'phone' => '9811122233'],
            'items' => [
                ['type' => 'service', 'description' => 'Remote repair', 'quantity' => 1, 'unit_price' => 20000],
                ['type' => 'part', 'item_id' => $this->part->id, 'quantity' => 2],
            ],
            'discount' => 10000,
            'payment_method' => 'cash',
            'amount_paid' => 80000,
        ], $extra));
    }

    public function test_walk_in_bill_totals_stock_payment_and_pdf(): void
    {
        $res = $this->bill()->assertCreated()
            ->assertJsonPath('data.source', 'walk_in')
            ->assertJsonPath('data.job_id', null)
            ->assertJsonPath('data.total_service_charge', 20000)
            ->assertJsonPath('data.total_spare_charge', 100000)
            ->assertJsonPath('data.discount_amount', 10000)
            ->assertJsonPath('data.total_amount', 110000)
            ->assertJsonPath('data.paid_amount', 80000)
            ->assertJsonPath('data.balance_amount', 30000)
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(1, 'data.payments');
        $this->assertSame(8.0, $this->stock());

        $id = $res->json('data.id');
        $this->getJson("/api/v1/invoices/{$id}")->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.creator.id', $this->admin->id);
        $this->getJson('/api/v1/invoices?source=walk_in')->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/v1/invoices/{$id}/pdf")->assertOk();

        // Same phone again: the existing customer is reused, not duplicated.
        $this->bill(['payment_method' => 'credit', 'amount_paid' => null])->assertCreated()->assertJsonPath('data.is_credit', true)
            ->assertJsonPath('data.customer_id', $res->json('data.customer_id'));
    }

    public function test_rejected_payment_or_short_stock_leaves_nothing_behind(): void
    {
        $this->bill(['payment_method' => 'upi'])->assertStatus(422)->assertJsonValidationErrors('reference_no');
        $this->bill(['items' => [['type' => 'part', 'item_id' => $this->part->id, 'quantity' => 50]], 'discount' => 0, 'amount_paid' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
        // Paying more than the bill rolls the whole bill back.
        $this->bill(['amount_paid' => 999999])->assertStatus(422);

        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Invoice::count()));
        $this->assertSame(10.0, $this->stock());
    }

    public function test_expenses_and_books_summary_and_day_book(): void
    {
        $this->bill()->assertCreated(); // ₹800 collected in cash
        $fuel = $this->inTenant($this->tenant, fn () => ExpenseCategory::where('name', 'Fuel & travel')->firstOrFail());

        $this->postJson('/api/v1/expenses', [
            'expense_date' => now($this->tenant->timezone)->toDateString(), 'expense_category_id' => $fuel->id,
            'amount' => 30000, 'payment_method' => 'cash', 'paid_to' => 'HP petrol pump',
        ])->assertCreated()->assertJsonPath('data.category.name', 'Fuel & travel');

        $this->getJson('/api/v1/expenses')->assertOk()->assertJsonPath('meta.total', 30000)->assertJsonPath('meta.by_category.Fuel & travel', 30000);

        $this->getJson('/api/v1/books/summary')->assertOk()
            ->assertJsonPath('data.income', 80000)
            ->assertJsonPath('data.income_by_source.walk_in', 80000)
            ->assertJsonPath('data.expense', 30000)
            ->assertJsonPath('data.net', 50000)
            ->assertJsonPath('data.cash.net', 50000);

        $this->getJson('/api/v1/books/day-book')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.in', 80000)->assertJsonPath('meta.out', 30000);
    }

    public function test_permissions(): void
    {
        $coordinator = $this->makeUser($this->tenant, Role::Coordinator);
        $tech = $this->makeUser($this->tenant, Role::Technician);

        $this->actingAsUser($coordinator)->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'A', 'phone' => '9811100000'],
            'items' => [['type' => 'service', 'description' => 'Check-up', 'quantity' => 1, 'unit_price' => 10000]],
        ])->assertCreated();
        $this->getJson('/api/v1/expenses')->assertForbidden();
        $this->getJson('/api/v1/books/summary')->assertForbidden();

        $this->forgetGuards();
        $this->actingAsUser($tech)->postJson('/api/v1/walk-in-bills', ['items' => []])->assertForbidden();
    }
}
