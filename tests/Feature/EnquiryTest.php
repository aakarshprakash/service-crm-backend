<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Enquiries (leads): logging, follow-ups, and becoming a job or a quotation. */
class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->actingAsUser($this->admin);
    }

    private function enquiry(array $extra = []): array
    {
        return $this->postJson('/api/v1/enquiries', $extra + [
            'type' => 'service', 'name' => 'Meera', 'phone' => '9876500011', 'source' => 'phone',
            'product' => '1.5 ton split AC', 'details' => 'Not cooling since Monday',
        ])->assertCreated()->json('data');
    }

    public function test_an_enquiry_is_numbered_logged_and_listed(): void
    {
        $e = $this->enquiry(['follow_up_at' => now()->subDay()->toIso8601String()]);
        $this->assertStringStartsWith('ENQ-', $e['enquiry_number']);
        $this->assertSame('new', $e['status']);
        $this->assertNull($e['customer_id']);
        $this->assertSame('Enquiry logged.', $e['notes'][0]['note']);

        $this->enquiry(['type' => 'sales', 'name' => 'Ravi', 'phone' => '9876500022', 'expected_value' => 4500000, 'details' => 'Wants a new inverter AC']);

        $this->getJson('/api/v1/enquiries?status=open')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/enquiries?type=sales')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ravi');
        $this->getJson('/api/v1/enquiries?search=cooling')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/enquiries?status=overdue')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/enquiries/summary')->assertOk()
            ->assertJsonPath('data.new', 2)->assertJsonPath('data.overdue', 1)->assertJsonPath('data.open_value', 4500000);
    }

    public function test_the_phone_number_links_an_existing_customer(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['phone' => '9876500011']);
        $this->assertSame($customer->id, $this->enquiry()['customer_id']);
    }

    public function test_follow_ups_are_kept_and_move_the_next_call(): void
    {
        $e = $this->enquiry();
        $next = now()->addDays(2)->startOfMinute();
        $this->postJson("/api/v1/enquiries/{$e['id']}/notes", ['note' => 'Will decide after the weekend', 'follow_up_at' => $next->toIso8601String()])->assertOk()
            ->assertJsonPath('data.status', 'follow_up')->assertJsonPath('data.notes.0.note', 'Will decide after the weekend');
        $this->assertTrue(Enquiry::find($e['id'])->follow_up_at->equalTo($next));

        $this->postJson("/api/v1/enquiries/{$e['id']}/notes", ['note' => ''])->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_an_enquiry_can_be_lost_reopened_and_won(): void
    {
        $e = $this->enquiry();
        $this->postJson("/api/v1/enquiries/{$e['id']}/status", ['status' => 'lost'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/enquiries/{$e['id']}/status", ['status' => 'lost', 'reason' => 'Price too high'])->assertOk()
            ->assertJsonPath('data.status', 'lost')->assertJsonPath('data.lost_reason', 'Price too high');
        $this->postJson("/api/v1/enquiries/{$e['id']}/status", ['status' => 'converted'])->assertStatus(422);
        $this->postJson("/api/v1/enquiries/{$e['id']}/status", ['status' => 'reopen'])->assertOk()->assertJsonPath('data.status', 'follow_up')->assertJsonPath('data.lost_reason', null);
        $this->postJson("/api/v1/enquiries/{$e['id']}/status", ['status' => 'converted', 'reason' => 'Bought at the counter'])->assertOk()->assertJsonPath('data.status', 'converted');
    }

    public function test_booking_a_job_from_an_enquiry_converts_it(): void
    {
        $e = $this->enquiry(['address' => '12 MG Road', 'city' => 'Kochi']);
        // The customer is made from the enquiry, once.
        $customerId = $this->postJson("/api/v1/enquiries/{$e['id']}/customer")->assertOk()->json('data.id');
        $this->assertSame($customerId, $this->postJson("/api/v1/enquiries/{$e['id']}/customer")->assertOk()->json('data.id'));
        $this->assertSame('12 MG Road', Customer::find($customerId)->address);

        $job = $this->postJson('/api/v1/jobs', ['customer_id' => $customerId, 'priority' => 'medium', 'call_type' => 'crm_call', 'enquiry_id' => $e['id']])->assertCreated()->json('data');

        $this->getJson("/api/v1/enquiries/{$e['id']}")->assertOk()
            ->assertJsonPath('data.status', 'converted')->assertJsonPath('data.job.id', $job['id'])->assertJsonPath('data.customer_id', $customerId);
    }

    public function test_a_quotation_follows_the_enquiry_until_the_customer_answers(): void
    {
        $e = $this->enquiry(['type' => 'sales']);
        $customerId = $this->postJson("/api/v1/enquiries/{$e['id']}/customer")->assertOk()->json('data.id');
        $estimate = $this->postJson('/api/v1/estimates', [
            'customer_id' => $customerId, 'enquiry_id' => $e['id'],
            'items' => [['type' => 'part', 'description' => 'Split AC 1.5 ton', 'quantity' => 1, 'unit_price' => 4200000]],
        ])->assertCreated()->json('data');

        $this->getJson("/api/v1/enquiries/{$e['id']}")->assertJsonPath('data.status', 'follow_up')->assertJsonPath('data.estimate.id', $estimate['id']);

        $this->postJson("/api/v1/estimates/{$estimate['id']}/status", ['status' => 'approved'])->assertOk();
        $this->getJson("/api/v1/enquiries/{$e['id']}")->assertJsonPath('data.status', 'converted');
    }

    public function test_who_can_see_and_log_enquiries(): void
    {
        $e = $this->enquiry();
        foreach ([Role::Technician, Role::StoreKeeper, Role::HrExecutive] as $role) {
            $this->forgetGuards();
            $this->actingAsUser($this->makeUser($this->tenant, $role));
            $this->getJson('/api/v1/enquiries')->assertForbidden();
        }
        foreach ([Role::Coordinator, Role::ServiceTracker, Role::Accountant, Role::HeadEngineer] as $role) {
            $this->forgetGuards();
            $this->actingAsUser($this->makeUser($this->tenant, $role));
            $this->getJson('/api/v1/enquiries')->assertOk()->assertJsonCount(1, 'data');
        }

        // Another company never sees them.
        ['admin' => $other] = $this->makeTenant('other');
        $this->forgetGuards();
        $this->actingAsUser($other);
        $this->getJson('/api/v1/enquiries')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/enquiries/{$e['id']}")->assertNotFound();
    }
}
