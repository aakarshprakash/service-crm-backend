<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Job card: free-text product category with search, and the "part pending" status. */
class JobPartPendingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    public function test_product_category_is_saved_edited_and_searchable(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['name' => 'Asha']);
        $other = $this->makeCustomer($this->tenant, ['name' => 'Ravi', 'phone' => '9811100099']);
        $this->actingAsUser($this->admin);

        $id = $this->postJson('/api/v1/jobs', [
            'customer_id' => $customer->id, 'priority' => 'medium', 'call_type' => 'crm_call', 'product_category' => 'Split AC',
        ])->assertCreated()->assertJsonPath('data.product_category', 'Split AC')->json('data.id');
        $this->postJson('/api/v1/jobs', ['customer_id' => $other->id, 'priority' => 'medium', 'call_type' => 'crm_call', 'product_category' => 'Washing machine'])->assertCreated();

        $found = $this->getJson('/api/v1/jobs?search=split')->assertOk()->json('data');
        $this->assertSame([$id], array_column($found, 'id'));

        $this->patchJson("/api/v1/jobs/{$id}", ['product_category' => 'Cassette AC'])->assertOk();
        $this->getJson("/api/v1/jobs/{$id}")->assertJsonPath('data.product_category', 'Cassette AC');
        $this->assertCount(0, $this->getJson('/api/v1/jobs?search=split')->json('data'));

        $this->postJson('/api/v1/jobs', ['customer_id' => $customer->id, 'priority' => 'medium', 'call_type' => 'crm_call', 'product_category' => str_repeat('x', 101)])
            ->assertStatus(422)->assertJsonValidationErrors('product_category');
    }

    public function test_category_is_only_what_was_typed_and_carries_to_follow_ups(): void
    {
        $this->actingAsUser($this->admin);
        $type = $this->postJson('/api/v1/master/quick/categories', ['name' => 'INVERTER AC'])->json('data');
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Asha', 'phone' => '9811100001', 'products' => [['category_id' => $type['id']]]])->assertCreated()->json('data');
        $job = ['customer_id' => $customer['id'], 'customer_product_id' => $customer['products'][0]['id'], 'priority' => 'low', 'call_type' => 'crm_call'];

        // The unit's product type is not copied into the job's category.
        $this->postJson('/api/v1/jobs', $job)->assertCreated()->assertJsonPath('data.product_category', null);

        $typed = $this->postJson('/api/v1/jobs', $job + ['product_category' => 'Split AC'])->assertCreated()->assertJsonPath('data.product_category', 'Split AC')->json('data');
        $this->postJson("/api/v1/jobs/{$typed['id']}/follow-up", [])->assertCreated()->assertJsonPath('data.product_category', 'Split AC');
    }

    public function test_office_parks_a_job_and_part_received_returns_it_to_open(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $tech);
        $this->actingAsUser($this->admin);

        $this->postJson("/api/v1/jobs/{$job->id}/part-pending", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/jobs/{$job->id}/part-pending", ['note' => 'Compressor 1.5T'])->assertOk()
            ->assertJsonPath('data.status', 'part_pending')->assertJsonPath('data.part_pending_note', 'Compressor 1.5T');
        $since = $this->getJson("/api/v1/jobs/{$job->id}")->json('data.part_pending_at');
        $this->assertNotNull($since);

        // Correcting the note keeps the date the job started waiting.
        $this->postJson("/api/v1/jobs/{$job->id}/part-pending", ['note' => 'Compressor 2T'])->assertOk()->assertJsonPath('data.part_pending_at', $since);

        $this->getJson('/api/v1/jobs?status=part_pending')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/jobs/counts')->assertJsonPath('data.part_pending', 1);

        // No visit yet, so it is open again; the note is cleared and the technician is told.
        $this->postJson("/api/v1/jobs/{$job->id}/part-received")->assertOk()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.part_pending_note', null)->assertJsonPath('data.part_pending_at', null);
        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('type', 'part_received')->where('user_id', $tech->id)->exists());
        $this->postJson("/api/v1/jobs/{$job->id}/part-received")->assertStatus(422);
    }

    public function test_technician_closes_visit_as_part_pending_then_returns_with_the_part(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $tech, ['scheduled_at' => now()]);
        $this->punchIn($tech);
        $this->actingAsUser($tech);

        $v1 = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');

        // The office can't park a job while the visit is running.
        $this->forgetGuards();
        $this->actingAsUser($this->admin)->postJson("/api/v1/jobs/{$job->id}/part-pending", ['note' => 'PCB'])->assertStatus(422);
        $this->forgetGuards();
        $this->actingAsUser($tech);

        $this->postJson("/api/v1/visits/{$v1}/complete", ['status' => 'part_pending', 'service_summary' => 'PCB faulty, needs replacing'])->assertOk();
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.status', 'part_pending')
            ->assertJsonPath('data.part_pending_note', 'PCB faulty, needs replacing')
            ->assertJsonPath('data.visits.0.status', 'part_pending');

        // Not on today's list while waiting, but counted.
        $this->getJson('/api/v1/dashboard/technician')->assertOk()
            ->assertJsonPath('data.counts.part_pending', 1)->assertJsonCount(0, 'data.today');

        // The technician may carry the part along and start straight away.
        $v2 = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->assertNotSame($v1, $v2);
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.part_pending_note', null);
    }

    public function test_part_received_after_a_visit_goes_back_to_pending_and_closed_jobs_cannot_be_parked(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $tech);
        $this->punchIn($tech);
        $this->actingAsUser($tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'tele_call'])->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'part_pending', 'service_summary' => 'Fan motor'])->assertOk();

        $this->forgetGuards();
        $this->actingAsUser($this->admin);
        $this->postJson("/api/v1/jobs/{$job->id}/part-received")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->postJson("/api/v1/jobs/{$job->id}/cancel", ['reason' => 'Customer declined'])->assertOk();
        $this->postJson("/api/v1/jobs/{$job->id}/part-pending", ['note' => 'Fan motor'])->assertStatus(422);
    }

    public function test_technicians_cannot_use_the_office_actions(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $tech);

        $this->actingAsUser($tech)->postJson("/api/v1/jobs/{$job->id}/part-pending", ['note' => 'PCB'])->assertForbidden();
        $this->postJson("/api/v1/jobs/{$job->id}/part-received")->assertForbidden();
    }
}
