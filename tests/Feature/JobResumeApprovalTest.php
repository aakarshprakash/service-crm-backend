<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Expense;
use App\Models\JobVisit;
use App\Models\PunchLog;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DayEndService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pending jobs wait for the office before the next visit; end-of-day automation; old closed jobs leaving
 * the technician's app; and the daily cash register.
 */
class JobResumeApprovalTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Tenant $tenant;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->makeTenant();
        $this->tenant = $this->ctx['tenant'];
        $this->tech = $this->punchIn($this->makeUser($this->tenant, Role::Technician));
    }

    private function settings(array $changes): void
    {
        $this->tenant->update(['settings' => array_replace_recursive($this->tenant->fresh()->mergedSettings(), $changes)]);
    }

    /** A job with one visit closed as $status. */
    private function leftAs(string $status): ServiceJob
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech);
        $this->forgetGuards()->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => $status, 'service_summary' => 'Needs another look'])->assertOk();

        return $job;
    }

    public function test_a_job_left_pending_waits_for_the_office_and_stays_with_the_technician(): void
    {
        $job = $this->leftAs('pending');

        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.needs_resume_approval', true)->assertJsonPath('data.assigned_technician_id', $this->tech->id);
        $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertStatus(422)->assertJsonValidationErrors('job');
        $this->patchJson("/api/v1/jobs/{$job->id}/details", ['serial_no' => 'X'])->assertStatus(422);

        // The technician asks; the office is told.
        $this->postJson("/api/v1/jobs/{$job->id}/resume-request", ['note' => 'Customer is home after 4 pm'])->assertOk();
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.resume_request_note', 'Customer is home after 4 pm');
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->ctx['admin']->id, 'type' => 'job_resume_requested']);

        // A technician can't approve their own job.
        $this->postJson("/api/v1/jobs/{$job->id}/resume/approve")->assertForbidden();

        // Not yet: told why, still waiting.
        $this->forgetGuards()->actingAsUser($this->ctx['admin']);
        $this->postJson("/api/v1/jobs/{$job->id}/resume/reject", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/jobs/{$job->id}/resume/reject", ['note' => 'Part not in yet'])->assertOk();
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->tech->id, 'type' => 'job_resume_rejected']);
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.needs_resume_approval', true)->assertJsonPath('data.resume_requested_at', null);

        // Approved: one more visit.
        $this->postJson("/api/v1/jobs/{$job->id}/resume/approve")->assertOk();
        $this->postJson("/api/v1/jobs/{$job->id}/resume/approve")->assertStatus(422);
        $this->forgetGuards()->actingAsUser($this->tech);
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.needs_resume_approval', false);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated()->json('data.id');

        // Left pending again: the approval was used up.
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'pending', 'service_summary' => 'Still faulty'])->assertOk();
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.needs_resume_approval', true);
        // The earlier visits, with what was recorded on them, stay on the job.
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonCount(2, 'data.visits');
    }

    public function test_part_received_and_a_rejected_cancellation_count_as_the_go_ahead(): void
    {
        $job = $this->leftAs('part_pending');
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.needs_resume_approval', true);
        $this->forgetGuards()->actingAsUser($this->ctx['admin'])->postJson("/api/v1/jobs/{$job->id}/part-received")->assertOk();
        $this->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.status', 'pending')->assertJsonPath('data.needs_resume_approval', false);

        $other = $this->leftAs('pending');
        $this->postJson("/api/v1/jobs/{$other->id}/cancel-request", ['reason' => 'Customer moved to another city'])->assertOk();
        $this->forgetGuards()->actingAsUser($this->ctx['admin'])->postJson("/api/v1/jobs/{$other->id}/cancel/reject", ['note' => 'They are still here'])->assertOk();
        $this->forgetGuards()->actingAsUser($this->tech)->postJson("/api/v1/jobs/{$other->id}/visits/start", ['service_type' => 'office'])->assertCreated();
    }

    public function test_the_rule_can_be_switched_off_and_never_applies_to_a_job_not_yet_visited(): void
    {
        $fresh = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech, ['status' => 'pending']);
        $this->actingAsUser($this->tech)->getJson("/api/v1/jobs/{$fresh->id}")->assertJsonPath('data.needs_resume_approval', false);
        $this->postJson("/api/v1/jobs/{$fresh->id}/resume-request")->assertStatus(422);

        $job = $this->leftAs('pending');
        $this->settings(['jobs' => ['resume_needs_approval' => false]]);
        $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated();
    }

    public function test_closed_jobs_leave_the_technicians_app_after_the_set_days(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $recent = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'completed', 'completed_at' => now()->subDay()]);
        $old = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'completed', 'completed_at' => now()->subDays(3)]);
        $cancelled = $this->makeJob($this->tenant, $customer, $this->tech, ['status' => 'cancelled', 'cancelled_at' => now()->subDays(5)]);
        $open = $this->makeJob($this->tenant, $customer, $this->tech);

        $ids = fn () => collect($this->getJson('/api/v1/jobs')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->actingAsUser($this->tech);
        $this->assertSame([$recent->id, $open->id], $ids());
        $this->getJson('/api/v1/dashboard/technician')->assertJsonPath('data.counts.completed', 1);

        // Still on record for the office, and the setting can keep them all.
        $this->forgetGuards()->actingAsUser($this->ctx['admin']);
        $this->assertCount(4, $this->getJson('/api/v1/jobs')->json('data'));
        $this->putJson('/api/v1/settings/preferences', ['jobs' => ['tech_hide_closed_after_days' => 0]])->assertOk();
        $this->forgetGuards()->actingAsUser($this->tech);
        $this->assertSame(collect([$recent->id, $old->id, $cancelled->id, $open->id])->sort()->values()->all(), $ids());
    }

    public function test_settings_take_a_time_of_day_or_nothing(): void
    {
        $this->actingAsUser($this->ctx['admin']);
        $this->putJson('/api/v1/settings/preferences', ['attendance' => ['auto_punch_out_at' => '25:00']])->assertStatus(422);
        $this->putJson('/api/v1/settings/preferences', ['attendance' => ['auto_punch_out_at' => '21:30'], 'jobs' => ['auto_close_visits_at' => '22:00']])->assertOk()
            ->assertJsonPath('data.attendance.auto_punch_out_at', '21:30')->assertJsonPath('data.jobs.auto_close_visits_at', '22:00');
        $this->putJson('/api/v1/settings/preferences', ['attendance' => ['auto_punch_out_at' => null]])->assertOk()->assertJsonPath('data.attendance.auto_punch_out_at', null);
    }

    public function test_the_cutoff_is_the_set_time_that_day_or_the_next(): void
    {
        $tz = 'Asia/Kolkata';
        $at = fn (string $local) => CarbonImmutable::parse($local, $tz)->utc();
        $this->assertSame('2026-11-05 21:00', DayEndService::cutoff($at('2026-11-05 09:15'), '21:00', $tz)->timezone($tz)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-06 21:00', DayEndService::cutoff($at('2026-11-05 21:30'), '21:00', $tz)->timezone($tz)->format('Y-m-d H:i'));
    }

    public function test_forgotten_punch_out_and_visits_left_running_are_closed_at_the_set_time(): void
    {
        $this->settings(['attendance' => ['auto_punch_out_at' => '21:00'], 'jobs' => ['auto_close_visits_at' => '22:00']]);
        $tz = $this->tenant->timezone;
        $yesterday = CarbonImmutable::now($tz)->subDay();
        $morning = CarbonImmutable::parse($yesterday->toDateString().' 09:30', $tz)->utc();

        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $this->tech, ['status' => 'in_progress']);
        [$visit] = $this->inTenant($this->tenant, function () use ($job, $morning) {
            $log = new PunchLog(['user_id' => $this->tech->id, 'type' => 'in']);
            $log->created_at = $morning;
            $log->save();

            return [JobVisit::create(['job_id' => $job->id, 'technician_id' => $this->tech->id, 'visit_date' => $morning->toDateString(), 'service_type' => 'on_site', 'start_time' => $morning, 'status' => 'in_progress', 'service_summary' => 'Checked gas'])];
        });
        // Someone who punched in today, before the time, is left alone.
        $today = $this->punchIn($this->makeUser($this->tenant, Role::Coordinator));
        $this->inTenant($this->tenant, fn () => PunchLog::create(['user_id' => $today->id, 'type' => 'in']));
        if (CarbonImmutable::now($tz)->format('H:i') >= '21:00') {
            $this->markTestSkipped('Runs before 21:00 company time, so today\'s punch is still inside the day.');
        }

        $service = app(DayEndService::class);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => $service->autoCloseVisits($this->tenant->fresh())));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => $service->autoPunchOut($this->tenant->fresh())));
        // Running it again changes nothing.
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => $service->autoPunchOut($this->tenant->fresh())));

        $out = PunchLog::withoutGlobalScopes()->where('user_id', $this->tech->id)->where('type', 'out')->first();
        $this->assertSame('auto', $out->source);
        $this->assertSame($yesterday->toDateString().' 21:00', $out->created_at->timezone($tz)->format('Y-m-d H:i'));
        $this->assertSame('out', $this->tech->fresh()->punch_status);
        $this->assertSame('in', $today->fresh()->punch_status);

        $visit = $visit->fresh();
        $this->assertSame('pending', $visit->status);
        $this->assertTrue($visit->auto_closed);
        $this->assertSame("Checked gas\nAuto closed", $visit->service_summary);
        $this->assertSame($yesterday->toDateString().' 22:00', $visit->end_time->timezone($tz)->format('Y-m-d H:i'));
        $this->assertSame('pending', ServiceJob::withoutGlobalScopes()->find($job->id)->status->value);
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->tech->id, 'type' => 'visit_auto_closed']);
        $this->assertDatabaseHas('notification_logs', ['user_id' => $this->tech->id, 'type' => 'auto_punch_out']);
    }

    public function test_cash_register_groups_the_day_by_person_with_cash_and_bank_columns(): void
    {
        $this->actingAsUser($this->ctx['admin']);
        $customer = $this->makeCustomer($this->tenant, ['name' => 'Balachandran', 'phone' => '9447143028']);
        $job = $this->makeJob($this->tenant, $customer, $this->tech);
        $this->forgetGuards()->actingAsUser($this->tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->json('data.id');
        $action = $this->inTenant($this->tenant, fn () => \App\Models\ActionTakenOption::first());
        $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'completed', 'action_taken_id' => $action->id, 'service_summary' => 'Done', 'labour_charge' => 160000,
            'payment_method' => 'cash', 'amount_collected' => 60000,
        ])->assertOk();
        $invoiceId = ServiceJob::withoutGlobalScopes()->find($job->id)->invoice()->withoutGlobalScopes()->value('id');

        $this->forgetGuards()->actingAsUser($this->ctx['admin']);
        $this->postJson("/api/v1/invoices/{$invoiceId}/pay", ['amount' => 100000, 'method' => 'upi', 'reference_no' => 'UPI-1'])->assertSuccessful();
        $today = CarbonImmutable::now($this->tenant->timezone)->toDateString();
        $this->inTenant($this->tenant, function () use ($today) {
            Expense::create(['expense_date' => $today, 'amount' => 34000, 'payment_method' => 'cash', 'paid_to' => 'Sanoop', 'description' => 'T/A']);
            Expense::create(['expense_date' => $today, 'amount' => 64000, 'payment_method' => 'upi', 'paid_to' => 'sanoop ', 'description' => 'Spare']);
        });

        $day = $this->getJson("/api/v1/books/register?from={$today}&to={$today}")->assertOk()->json('data.days.0');
        $this->assertSame(['cash_in' => 60000, 'bank_in' => 100000, 'cash_out' => 34000, 'bank_out' => 64000], $day['totals']);
        // "From" whoever took the money: the technician on site, the office for the UPI payment.
        $from = collect($day['receipts'])->keyBy('party');
        $this->assertSame('9447143028', $from[$this->tech->name]['lines'][0]['phone']);
        $this->assertSame([60000, 0], [$from[$this->tech->name]['cash'], $from[$this->tech->name]['bank']]);
        $this->assertSame([0, 100000], [$from['Acme Admin']['cash'], $from['Acme Admin']['bank']]);
        $this->assertCount(1, $day['payments'], 'Same person, however it was typed, is one group');
        $this->assertSame([34000, 64000], [$day['payments'][0]['cash'], $day['payments'][0]['bank']]);
        $this->assertSame($day['opening']['cash'] + 26000, $day['closing']['cash']);

        $this->getJson('/api/v1/books/register?from=2026-01-01&to=2026-03-01')->assertStatus(422);
        $this->forgetGuards()->actingAsUser($this->tech)->getJson("/api/v1/books/register?from={$today}&to={$today}")->assertForbidden();
    }
}
