<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\NotificationLog;
use App\Models\ServiceJob;
use App\Support\GeoLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Technician expense claims (with the cash close), voice notes, customer location
 * sharing and the optional UPI reference.
 */
class FieldFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function setUpField(): array
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $admin = $this->makeUser($tenant, Role::Admin);
        $customer = $this->makeCustomer($tenant);
        $job = $this->makeJob($tenant, $customer, $tech);
        $category = $this->inTenant($tenant, fn () => ExpenseCategory::first());

        return compact('tenant', 'tech', 'admin', 'customer', 'job', 'category');
    }

    /** Completes a visit with a cash collection so the technician has cash in hand. */
    private function collectCash($tenant, $tech, ServiceJob $job, int $amount): void
    {
        $this->actingAsUser($tech);
        $visitId = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $action = $this->inTenant($tenant, fn () => ActionTakenOption::first());
        $this->postJson("/api/v1/visits/{$visitId}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Done',
            'labour_charge' => $amount, 'payment_method' => 'cash', 'amount_collected' => $amount,
        ])->assertOk();
    }

    public function test_cash_in_hand_claim_reduces_cash_close_and_books_expense_on_approval(): void
    {
        Storage::fake('private');
        ['tenant' => $tenant, 'tech' => $tech, 'admin' => $admin, 'job' => $job, 'category' => $category] = $this->setUpField();
        $this->collectCash($tenant, $tech, $job, 150000);
        $today = now($tenant->timezone)->toDateString();

        $this->actingAsUser($tech);
        $claimId = $this->postJson('/api/v1/my/expenses', [
            'expense_category_id' => $category->id, 'amount' => 20000, 'claim_date' => $today,
            'paid_from' => 'cash_in_hand', 'job_id' => $job->id, 'description' => 'Fuel',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->assertTrue(NotificationLog::where('user_id', $admin->id)->where('type', 'expense_claim')->exists());

        $this->postJson("/api/v1/my/expenses/{$claimId}/receipt", ['receipt' => UploadedFile::fake()->image('bill.jpg', 800, 1000)])
            ->assertOk()->assertJsonStructure(['data' => ['receipt_url']]);

        // Expected cash = 1500 collected − 200 spent.
        $this->getJson('/api/v1/accounts/cash-summary?date='.$today)->assertOk()
            ->assertJsonPath('data.expenses', 20000)->assertJsonPath('data.expected_in_hand', 130000)
            ->assertJsonCount(1, 'data.expense_claims');
        $this->getJson('/api/v1/my/expenses')->assertOk()->assertJsonPath('meta.pending_amount', 20000);

        $this->postJson('/api/v1/accounts/cash-close', ['date' => $today, 'amount_confirmed' => 130000])->assertCreated()
            ->assertJsonPath('data.total_expenses', 20000)->assertJsonPath('data.expected_in_hand', 130000);
        $this->assertNotNull($this->inTenant($tenant, fn () => ExpenseClaim::find($claimId))->cash_close_id);
        // Locked into the close: can't be withdrawn any more.
        $this->deleteJson("/api/v1/my/expenses/{$claimId}")->assertStatus(422);

        // Technicians can't approve.
        $this->postJson("/api/v1/expense-claims/{$claimId}/approve")->assertForbidden();

        $this->actingAsUser($admin);
        $this->getJson('/api/v1/expense-claims')->assertOk()->assertJsonPath('meta.pending_count', 1);
        $this->postJson("/api/v1/expense-claims/{$claimId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $expense = $this->inTenant($tenant, fn () => Expense::firstOrFail());
        $this->assertSame([20000, 'cash', $tech->id], [$expense->amount, $expense->payment_method, $expense->user_id]);
        $this->assertTrue(NotificationLog::where('user_id', $tech->id)->where('type', 'expense_claim_decided')->exists());
        $this->postJson("/api/v1/expense-claims/{$claimId}/approve")->assertStatus(422);
    }

    public function test_rejecting_a_claim_in_a_submitted_close_puts_the_cash_back(): void
    {
        ['tenant' => $tenant, 'tech' => $tech, 'admin' => $admin, 'job' => $job, 'category' => $category] = $this->setUpField();
        $this->collectCash($tenant, $tech, $job, 100000);
        $today = now($tenant->timezone)->toDateString();
        $this->actingAsUser($tech);
        $claimId = $this->postJson('/api/v1/my/expenses', ['expense_category_id' => $category->id, 'amount' => 30000, 'claim_date' => $today, 'paid_from' => 'cash_in_hand'])->json('data.id');
        $closeId = $this->postJson('/api/v1/accounts/cash-close', ['date' => $today, 'amount_confirmed' => 70000])->json('data.id');

        $this->actingAsUser($admin);
        $this->postJson("/api/v1/expense-claims/{$claimId}/reject")->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/expense-claims/{$claimId}/reject", ['note' => 'No bill'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->getJson("/api/v1/accounts/cash-close/{$closeId}")->assertOk()
            ->assertJsonPath('data.close.expected_in_hand', 100000)->assertJsonPath('data.close.total_expenses', 0);
        $this->assertSame(0, $this->inTenant($tenant, fn () => Expense::count()));
    }

    public function test_own_money_claim_needs_reimbursement_method_and_rules(): void
    {
        ['tenant' => $tenant, 'tech' => $tech, 'admin' => $admin, 'category' => $category] = $this->setUpField();
        $other = $this->makeUser($tenant, Role::Technician);
        $otherJob = $this->makeJob($tenant, $this->makeCustomer($tenant, ['phone' => '9811111111']), $other);
        $today = now($tenant->timezone)->toDateString();

        $this->actingAsUser($tech);
        $this->postJson('/api/v1/my/expenses', ['expense_category_id' => $category->id, 'amount' => 5000, 'claim_date' => $today, 'paid_from' => 'own_money', 'job_id' => $otherJob->id])
            ->assertStatus(422)->assertJsonValidationErrors('job_id');
        $this->postJson('/api/v1/my/expenses', ['expense_category_id' => $category->id, 'amount' => 5000, 'claim_date' => now()->addDays(3)->toDateString(), 'paid_from' => 'own_money'])
            ->assertStatus(422)->assertJsonValidationErrors('claim_date');
        $claimId = $this->postJson('/api/v1/my/expenses', ['expense_category_id' => $category->id, 'amount' => 5000, 'claim_date' => $today, 'paid_from' => 'own_money'])->json('data.id');

        // Own-money claims don't touch the cash close.
        $this->getJson('/api/v1/accounts/cash-summary?date='.$today)->assertJsonPath('data.expenses', 0);

        $this->actingAsUser($admin);
        $this->postJson("/api/v1/expense-claims/{$claimId}/approve")->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->postJson("/api/v1/expense-claims/{$claimId}/approve", ['payment_method' => 'upi'])->assertOk();
        $this->assertSame('upi', $this->inTenant($tenant, fn () => Expense::firstOrFail())->payment_method);

        // Office staff can claim own money, never "cash in hand".
        $this->postJson('/api/v1/my/expenses', ['expense_category_id' => $category->id, 'amount' => 5000, 'claim_date' => $today, 'paid_from' => 'cash_in_hand'])
            ->assertStatus(422)->assertJsonValidationErrors('paid_from');
    }

    public function test_voice_notes_upload_play_and_delete(): void
    {
        Storage::fake('private');
        ['tenant' => $tenant, 'tech' => $tech, 'job' => $job] = $this->setUpField();
        $this->actingAsUser($tech);
        $visitId = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->json('data.id');

        $this->postJson("/api/v1/visits/{$visitId}/voice-notes", ['audio' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')])
            ->assertStatus(422)->assertJsonValidationErrors('audio');
        $note = $this->postJson("/api/v1/visits/{$visitId}/voice-notes", ['audio' => UploadedFile::fake()->create('note.m4a', 120, 'audio/mp4'), 'duration' => 14])
            ->assertCreated()->assertJsonPath('data.duration_seconds', 14)->json('data');

        $this->getJson("/api/v1/visits/{$visitId}")->assertJsonCount(1, 'data.voice_notes');
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonCount(1, 'data.voice_notes');
        $this->get($note['url'])->assertOk();

        $this->deleteJson("/api/v1/visits/{$visitId}/voice-notes/{$note['id']}")->assertOk();
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_customer_location_from_link_office_and_technician(): void
    {
        ['tenant' => $tenant, 'tech' => $tech, 'admin' => $admin, 'customer' => $customer, 'job' => $job] = $this->setUpField();

        $this->actingAsUser($admin);
        $this->postJson("/api/v1/jobs/{$job->id}/location", ['link' => 'hello there'])->assertStatus(422)->assertJsonValidationErrors('link');
        $this->postJson("/api/v1/jobs/{$job->id}/location", ['link' => 'https://maps.google.com/?q=12.9716,77.5946'])
            ->assertOk()->assertJsonPath('data.lat', 12.9716)->assertJsonPath('data.location_source', 'office');
        $this->assertTrue(NotificationLog::where('user_id', $tech->id)->where('type', 'job_location')->exists());

        // Link for the customer to share their live location.
        $link = $this->postJson("/api/v1/jobs/{$job->id}/location-request", ['send' => true])->assertOk()->json('data.link');
        $token = basename($link);
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonMissingPath('data.location_token');

        $this->forgetGuards();
        $this->getJson("/api/v1/share-location/{$token}")->assertOk()->assertJsonPath('data.call_id', $job->crm_call_id);
        $this->postJson("/api/v1/share-location/{$token}", ['lat' => 13.0, 'lng' => 77.6, 'accuracy' => 12])->assertOk();
        $fresh = $this->inTenant($tenant, fn () => Customer::find($customer->id));
        $this->assertSame([13.0, 77.6, 'customer'], [$fresh->lat, $fresh->lng, $fresh->location_source]);
        $this->getJson('/api/v1/share-location/'.str_repeat('a', 40))->assertNotFound();

        // Technician on site.
        $this->actingAsUser($tech);
        $visitId = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->json('data.id');
        $this->postJson("/api/v1/visits/{$visitId}/customer-location", ['lat' => 12.5, 'lng' => 77.1])->assertOk()->assertJsonPath('data.location_source', 'technician');
    }

    public function test_geo_link_parsing_and_short_link_expansion(): void
    {
        $this->assertSame(['lat' => 12.9716, 'lng' => 77.5946], GeoLink::parse('https://maps.google.com/?q=12.9716,77.5946'));
        $this->assertSame(['lat' => 12.97, 'lng' => 77.59], GeoLink::parse('https://www.google.com/maps/place/Shop/@12.9,77.5,17z/data=!3m1!4b1!4m5!3m4!1s0x0:0x0!8m2!3d12.97!4d77.59'));
        $this->assertSame(['lat' => 12.9, 'lng' => 77.5], GeoLink::parse(' 12.9 , 77.5 '));
        $this->assertNull(GeoLink::parse('https://example.com/?q=hello'));

        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/X/data=!3d12.95!4d77.61']),
        ]);
        $this->assertSame(['lat' => 12.95, 'lng' => 77.61], GeoLink::resolve('https://maps.app.goo.gl/abc123'));
        // Never follows links to other hosts.
        $this->assertNull(GeoLink::resolve('https://evil.example.com/redirect'));
    }

    public function test_upi_payment_without_reference_is_accepted(): void
    {
        ['tenant' => $tenant, 'tech' => $tech, 'job' => $job] = $this->setUpField();
        $this->actingAsUser($tech);
        $visitId = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->json('data.id');
        $action = $this->inTenant($tenant, fn () => ActionTakenOption::first());
        $this->postJson("/api/v1/visits/{$visitId}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Done',
            'labour_charge' => 50000, 'payment_method' => 'upi', 'amount_collected' => 50000,
        ])->assertOk()->assertJsonPath('data.payment.method', 'upi');
    }
}
