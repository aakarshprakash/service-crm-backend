<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\CashClose;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\JobImage;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Permanent delete of test entries: off until an admin switches it on with their password. */
class HardDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $tech;

    private InventoryItem $part;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tech = $this->punchIn($this->makeUser($this->tenant, Role::Technician));
        $this->part = $this->makeItem($this->tenant, 5);
    }

    private function stock(): float
    {
        return (float) $this->inTenant($this->tenant, fn () => InventoryStock::where('item_id', $this->part->id)->sum('quantity_available'));
    }

    private function switchOn(): void
    {
        $this->forgetGuards()->actingAsUser($this->admin)->putJson('/api/v1/hard-delete', ['enabled' => true, 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.enabled', true);
    }

    /** A completed job with a photo, two parts used and ₹1,500 collected in cash. */
    private function finishedJob(): ServiceJob
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
        $action = $this->inTenant($this->tenant, fn () => ActionTakenOption::first());
        $this->forgetGuards()->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/images", ['type' => 'bill', 'image' => UploadedFile::fake()->image('bill.jpg', 400, 300)])->assertCreated();
        $this->postJson("/api/v1/visits/{$visit}/spares", ['item_id' => $this->part->id, 'quantity' => 2])->assertCreated();
        $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Test', 'labour_charge' => 50000, 'payment_method' => 'cash', 'amount_collected' => 150000,
        ])->assertOk();

        return $job;
    }

    public function test_it_is_off_by_default_and_needs_the_admins_password(): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant));
        $this->actingAsUser($this->admin);
        $this->getJson('/api/v1/hard-delete')->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.hard_delete', false);
        $this->deleteJson("/api/v1/hard-delete/job/{$job->id}", ['confirm' => $job->crm_call_id])->assertStatus(422)->assertJsonValidationErrors('hard_delete');

        $this->putJson('/api/v1/hard-delete', ['enabled' => true, 'password' => 'wrong-password'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/hard-delete', ['enabled' => true])->assertStatus(422);
        // It cannot be slipped in through the ordinary preferences form.
        $this->putJson('/api/v1/settings/preferences', ['hard_delete' => ['enabled' => true, 'enabled_at' => now()->toIso8601String()]])->assertOk();
        $this->getJson('/api/v1/hard-delete')->assertJsonPath('data.enabled', false);

        $this->switchOn();
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.hard_delete', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hard_delete.enabled', 'user_id' => $this->admin->id]);

        // Only admins, and it switches itself off after a day.
        $this->forgetGuards()->actingAsUser($this->makeUser($this->tenant, Role::Accountant))->getJson('/api/v1/hard-delete')->assertForbidden();
        $this->travel(25)->hours();
        $this->forgetGuards()->actingAsUser($this->admin)->getJson('/api/v1/hard-delete')->assertJsonPath('data.enabled', false);
        $this->deleteJson("/api/v1/hard-delete/job/{$job->id}", ['confirm' => $job->crm_call_id])->assertStatus(422);
    }

    public function test_deleting_a_job_removes_everything_and_returns_the_parts(): void
    {
        $job = $this->finishedJob();
        $this->assertEquals(3.0, $this->stock());
        $file = $this->inTenant($this->tenant, fn () => JobImage::where('job_id', $job->id)->value('file_path'));
        Storage::disk('private')->assertExists($file);
        // The technician hands the cash over: the receipt is now in a submitted close.
        $this->postJson('/api/v1/accounts/cash-close', ['date' => now($this->tenant->timezone)->toDateString(), 'amount_confirmed' => 150000])->assertCreated();

        $this->switchOn();
        $preview = $this->getJson("/api/v1/hard-delete/job/{$job->id}")->assertOk()->assertJsonPath('data.confirm', $job->crm_call_id)->json('data');
        $this->assertNotEmpty($preview['warnings']);
        $this->deleteJson("/api/v1/hard-delete/job/{$job->id}", ['confirm' => 'something else'])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->deleteJson("/api/v1/hard-delete/job/{$job->id}", ['confirm' => strtolower($job->crm_call_id)])->assertOk();

        $this->inTenant($this->tenant, function () use ($job) {
            $this->assertNull(ServiceJob::find($job->id));
            $this->assertSame(0, Invoice::where('job_id', $job->id)->count());
            $this->assertSame(0, Payment::count());
            $this->assertSame(0, JobImage::count());
            $close = CashClose::first();
            $this->assertSame(0, $close->total_cash_collected);
            $this->assertSame(0, $close->expected_in_hand);
            $this->assertSame(0, $close->closing_balance);
        });
        $this->assertEquals(5.0, $this->stock());
        Storage::disk('private')->assertMissing($file);
        $this->assertDatabaseHas('audit_logs', ['action' => 'hard_deleted', 'model' => 'ServiceJob', 'model_id' => $job->id]);
    }

    public function test_walk_in_bills_receipts_and_customers(): void
    {
        $bill = $this->actingAsUser($this->admin)->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Walk-in Ravi', 'phone' => '9811122233'],
            'items' => [['type' => 'service', 'description' => 'Remote repair', 'quantity' => 1, 'unit_price' => 20000], ['type' => 'part', 'item_id' => $this->part->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'amount_paid' => 50000,
        ])->assertCreated()->json('data');
        $this->assertEquals(3.0, $this->stock());
        $customerId = $bill['customer_id'];
        $this->switchOn();

        // A customer with a bill can't go first.
        $this->getJson("/api/v1/hard-delete/customer/{$customerId}")->assertOk()->assertJsonPath('data.blocked', 'This customer still has 1 bill. Delete those first.');
        $this->deleteJson("/api/v1/hard-delete/customer/{$customerId}", ['confirm' => 'Walk-in Ravi'])->assertStatus(422);

        // One receipt: the bill goes back to owing it.
        $payment = $this->inTenant($this->tenant, fn () => Payment::where('invoice_id', $bill['id'])->firstOrFail());
        $this->deleteJson("/api/v1/hard-delete/payment/{$payment->id}", ['confirm' => $payment->receipt_number])->assertOk();
        $this->getJson("/api/v1/invoices/{$bill['id']}")->assertJsonPath('data.paid_amount', 0)->assertJsonPath('data.payment_status', 'unpaid');

        // The bill: its parts are back on the shelf.
        $this->deleteJson("/api/v1/hard-delete/invoice/{$bill['id']}", ['confirm' => $bill['invoice_number']])->assertOk();
        $this->assertEquals(5.0, $this->stock());
        $this->getJson("/api/v1/invoices/{$bill['id']}")->assertNotFound();

        // And now the customer.
        $this->deleteJson("/api/v1/hard-delete/customer/{$customerId}", ['confirm' => 'walk-in ravi'])->assertOk();
        $this->assertNull($this->inTenant($this->tenant, fn () => Customer::withTrashed()->find($customerId)));

        // A job's invoice only goes with its job.
        $job = $this->finishedJob();
        $invoice = $this->inTenant($this->tenant, fn () => Invoice::where('job_id', $job->id)->firstOrFail());
        $this->forgetGuards()->actingAsUser($this->admin)->deleteJson("/api/v1/hard-delete/invoice/{$invoice->id}", ['confirm' => $invoice->invoice_number])->assertStatus(422);
    }

    public function test_expenses_and_income_go_even_on_a_closed_day(): void
    {
        $this->actingAsUser($this->admin);
        $category = $this->inTenant($this->tenant, fn () => ExpenseCategory::first());
        $today = now($this->tenant->timezone)->toDateString();
        $expense = $this->postJson('/api/v1/expenses', ['expense_date' => $today, 'expense_category_id' => $category->id, 'amount' => 25000, 'payment_method' => 'cash'])->assertCreated()->json('data.id');
        $income = $this->postJson('/api/v1/incomes', ['income_date' => $today, 'income_category_id' => $this->inTenant($this->tenant, fn () => \App\Models\IncomeCategory::first())->id, 'amount' => 40000, 'payment_method' => 'cash'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/books/day-close', ['date' => $today, 'opening_cash' => 0, 'counted_cash' => 15000])->assertCreated();

        // The ordinary delete respects the closed day.
        $this->deleteJson("/api/v1/expenses/{$expense}")->assertStatus(422);

        $this->switchOn();
        $this->getJson("/api/v1/hard-delete/expense/{$expense}")->assertOk()->assertJsonCount(1, 'data.warnings');
        $this->deleteJson("/api/v1/hard-delete/expense/{$expense}", ['confirm' => 'delete'])->assertOk();
        $this->deleteJson("/api/v1/hard-delete/income/{$income}", ['confirm' => 'DELETE'])->assertOk();
        $this->assertDatabaseMissing('expenses', ['id' => $expense]);
        $this->assertDatabaseMissing('incomes', ['id' => $income]);
    }
}
