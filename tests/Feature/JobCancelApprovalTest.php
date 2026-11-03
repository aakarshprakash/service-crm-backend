<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\NotificationLog;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A technician can only ask to cancel: the office decides, and a cancelled job always carries its reason. */
class JobCancelApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $tech;

    private ServiceJob $job;

    private int $visit;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tech = $this->makeUser($this->tenant, Role::Technician);
        $this->job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
        $this->punchIn($this->tech);
        $this->actingAsUser($this->tech);
        $this->visit = $this->postJson("/api/v1/jobs/{$this->job->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
    }

    private function asAdmin(): void
    {
        $this->forgetGuards();
        $this->actingAsUser($this->admin);
    }

    private function requestCancel(string $reason = 'Customer sold the unit'): void
    {
        $this->postJson("/api/v1/visits/{$this->visit}/complete", ['status' => 'cancelled', 'service_summary' => $reason])->assertOk();
    }

    public function test_technician_cancelling_only_asks_and_the_job_stays_open(): void
    {
        $this->postJson("/api/v1/visits/{$this->visit}/complete", ['status' => 'cancelled'])
            ->assertStatus(422)->assertJsonValidationErrors('service_summary')->assertJsonPath('errors.service_summary.0', 'Write why this job should be cancelled.');

        $this->requestCancel();

        $job = $this->getJson("/api/v1/jobs/{$this->job->id}")->assertOk()->json('data');
        $this->assertSame('cancel_requested', $job['status']);
        $this->assertSame('Customer sold the unit', $job['cancel_reason']);
        $this->assertSame($this->tech->id, $job['cancel_requested_by']);
        $this->assertNotNull($job['cancel_requested_at']);
        $this->assertNull($job['cancelled_at']);
        $this->assertSame('cancelled', $job['visits'][0]['status']);

        // Those who can decide are told; the technician can't start it again meanwhile.
        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('type', 'job_cancel_requested')->where('user_id', $this->admin->id)->exists());
        $this->postJson("/api/v1/jobs/{$this->job->id}/visits/start", ['service_type' => 'tele_call'])->assertStatus(422);
    }

    public function test_admin_approves_with_a_reason_and_the_job_closes(): void
    {
        $this->requestCancel();
        $this->asAdmin();

        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/approve", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/approve", ['reason' => 'Unit sold, confirmed by phone'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Unit sold, confirmed by phone');

        $job = ServiceJob::withoutGlobalScopes()->find($this->job->id);
        $this->assertNotNull($job->cancelled_at);
        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('type', 'job_cancel_approved')->where('user_id', $this->tech->id)->exists());

        // Decided once.
        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/approve", ['reason' => 'Again'])->assertStatus(422);
        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/reject", ['note' => 'Again'])->assertStatus(422);
    }

    public function test_admin_rejects_and_the_technician_can_go_back(): void
    {
        $this->requestCancel();
        $this->asAdmin();

        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/reject", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/reject", ['note' => 'Customer still wants it fixed'])->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.cancel_reason', null)
            ->assertJsonPath('data.cancel_requested_by', null)->assertJsonPath('data.cancel_requested_at', null);

        $this->assertTrue(NotificationLog::withoutGlobalScopes()->where('type', 'job_cancel_rejected')->where('user_id', $this->tech->id)->where('message', 'like', '%Customer still wants it fixed%')->exists());

        $this->forgetGuards();
        $this->actingAsUser($this->tech);
        $this->postJson("/api/v1/jobs/{$this->job->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated();
    }

    public function test_only_admins_coordinators_and_head_engineers_decide(): void
    {
        $this->requestCancel();

        foreach ([Role::Technician, Role::ServiceTracker, Role::Accountant, Role::StoreKeeper] as $role) {
            $this->forgetGuards();
            $this->actingAsUser($this->makeUser($this->tenant, $role));
            $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/approve", ['reason' => 'x'])->assertForbidden();
            $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/reject", ['note' => 'x'])->assertForbidden();
        }

        $coordinator = $this->makeUser($this->tenant, Role::Coordinator);
        $engineer = $this->makeUser($this->tenant, Role::HeadEngineer);
        $tracker = $this->makeUser($this->tenant, Role::ServiceTracker);
        $this->forgetGuards();
        $this->actingAsUser($coordinator);
        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/reject", ['note' => 'Customer still wants it fixed'])->assertOk()->assertJsonPath('data.status', 'pending');

        // Back to the technician, who asks again; everyone who can decide is told, nobody else.
        $this->forgetGuards();
        $this->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$this->job->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'cancelled', 'service_summary' => 'Customer not reachable'])->assertOk();
        $told = NotificationLog::withoutGlobalScopes()->where('type', 'job_cancel_requested')->pluck('user_id');
        $this->assertTrue($told->contains($coordinator->id) && $told->contains($engineer->id) && $told->contains($this->admin->id));
        $this->assertFalse($told->contains($tracker->id));

        $this->forgetGuards();
        $this->actingAsUser($engineer);
        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel/approve", ['reason' => 'Customer not reachable'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_nothing_else_can_slip_past_the_decision(): void
    {
        $this->requestCancel();
        $this->forgetGuards();
        $this->actingAsUser($this->makeUser($this->tenant, Role::ServiceTracker));

        $this->postJson("/api/v1/jobs/{$this->job->id}/cancel", ['reason' => 'Skip the admin'])->assertStatus(422);
        $this->patchJson("/api/v1/jobs/{$this->job->id}/assign", ['technician_id' => $this->tech->id])->assertStatus(422);
        $this->patchJson("/api/v1/jobs/{$this->job->id}/reschedule", ['scheduled_at' => now()->addDay()->toIso8601String()])->assertStatus(422);
        $this->postJson("/api/v1/jobs/{$this->job->id}/part-pending", ['note' => 'PCB'])->assertStatus(422);
        $this->assertSame('cancel_requested', ServiceJob::withoutGlobalScopes()->find($this->job->id)->status->value);
    }

    public function test_office_cancels_directly_but_must_give_a_reason(): void
    {
        $other = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant, ['phone' => '9811100055']), $this->tech);
        $coordinator = $this->makeUser($this->tenant, Role::Coordinator);
        $this->forgetGuards();
        $this->actingAsUser($coordinator);

        $this->postJson("/api/v1/jobs/{$other->id}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/jobs/{$other->id}/cancel", ['reason' => str_repeat('r', 300)])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', str_repeat('r', 300));
    }

    public function test_it_counts_as_open_work_and_customers_see_it_as_pending(): void
    {
        $this->requestCancel();
        $this->asAdmin();

        $this->getJson('/api/v1/jobs/counts')->assertJsonPath('data.cancel_requested', 1);
        $this->getJson('/api/v1/jobs?status=cancel_requested')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.jobs.by_status.cancel_requested', 1);
        $this->getJson('/api/v1/jobs/location-analysis')->assertJsonPath('data.totals.active', 1)->assertJsonPath('data.locations.0.cancel_requested', 1);
    }

    public function test_a_long_technician_reason_fits(): void
    {
        $this->requestCancel(str_repeat('x', 2000));
        $this->assertSame(500, mb_strlen(ServiceJob::withoutGlobalScopes()->find($this->job->id)->cancel_reason));
    }
}
