<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Models\JobStatusHistory;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobService
{
    public function __construct(private NotificationService $notifications, private AutoAssigner $autoAssigner) {}

    public function create(array $data, ?User $creator): ServiceJob
    {
        $job = DB::transaction(function () use ($data, $creator) {
            $tenant = Tenant::findOrFail($creator?->tenant_id ?? app(TenantContext::class)->id());
            if (! empty($data['assigned_technician_id'])) {
                $this->technicianOrFail((int) $data['assigned_technician_id']);
            }

            $job = new ServiceJob($data);
            $job->crm_call_id = ! empty($data['crm_call_id'])
                ? $data['crm_call_id']
                : DocumentNumber::next($tenant, 'job');
            $job->status = JobStatus::Open;
            $job->created_by = $creator?->id;
            if (empty($job->branch_id)) {
                $job->branch_id = $job->customer?->branch_id ?? $creator?->branch_id;
            }
            $job->save();
            $this->history($job, JobStatus::Open, $creator?->id, $job->parent_job_id ? 'Follow-up call created' : 'Job created');

            return $job;
        });

        DB::afterCommit(function () use ($job) {
            $this->notifications->notifyCustomer($job, $job->parent_job_id ? 'followup_reminder' : 'job_created');
            if ($job->assigned_technician_id) {
                $this->notifyAssignment($job, 'New job assigned');
            }
        });

        return $job;
    }

    public function assign(ServiceJob $job, int $technicianId, ?string $scheduledAt, User $by, ?string $note = null): ServiceJob
    {
        $this->ensureNotFinal($job);
        $technician = $this->technicianOrFail($technicianId);
        if ($job->activeVisit()->exists() && $job->assigned_technician_id !== $technicianId) {
            throw ValidationException::withMessages(['technician' => 'A visit is in progress. Close it before reassigning.']);
        }

        $reassigned = $job->assigned_technician_id && $job->assigned_technician_id !== $technicianId;
        $previous = $job->assigned_technician_id;
        $job->assigned_technician_id = $technicianId;
        if ($scheduledAt) {
            $job->scheduled_at = $scheduledAt;
        }
        $job->save();
        $this->history($job, $job->status, $by->id, $note ?? (($reassigned ? 'Reassigned' : 'Assigned')." to {$technician->name}"));

        if ($reassigned && $old = User::inTenant()->find($previous)) {
            $this->notifications->notifyUser($old, 'job_reassigned', 'Job reassigned', "Job {$job->crm_call_id} has been reassigned to another technician.", ['job_id' => $job->id]);
        }
        $this->notifyAssignment($job, $reassigned ? 'Job reassigned to you' : 'New job assigned');
        $this->notifications->notifyCustomer($job, 'technician_assigned');

        return $job;
    }

    /**
     * Assigns the job to the least-loaded technician for its service location.
     *
     * @return array{assigned: bool, technician: ?array{id: int, name: string}, open_jobs: ?int, reason: ?string}
     */
    public function autoAssign(ServiceJob $job, User $by): array
    {
        $this->ensureNotFinal($job);
        $pick = $this->autoAssigner->pick($job);
        if (! $pick['technician']) {
            return ['assigned' => false, 'technician' => null, 'open_jobs' => null, 'reason' => $pick['reason']];
        }
        $tech = $pick['technician'];
        $this->assign($job, $tech->id, null, $by, "Auto-assigned to {$tech->name} ({$pick['open_jobs']} open jobs at the time)");

        return ['assigned' => true, 'technician' => ['id' => $tech->id, 'name' => $tech->name], 'open_jobs' => $pick['open_jobs'], 'reason' => null];
    }

    public function reschedule(ServiceJob $job, string $scheduledAt, ?string $reason, User $by): ServiceJob
    {
        $this->ensureNotFinal($job);
        $job->update(['scheduled_at' => $scheduledAt]);
        $this->history($job, $job->status, $by->id, 'Rescheduled'.($reason ? ": $reason" : ''));

        if ($job->assigned_technician_id && $tech = User::inTenant()->find($job->assigned_technician_id)) {
            $this->notifications->notifyUser($tech, 'job_rescheduled', 'Job rescheduled',
                "Job {$job->crm_call_id} rescheduled to ".$job->scheduled_at->timezone($tech->tenant->timezone)->format('d M, h:i A'), ['job_id' => $job->id]);
        }

        return $job;
    }

    public function cancel(ServiceJob $job, string $reason, User $by): ServiceJob
    {
        return DB::transaction(function () use ($job, $reason, $by) {
            $this->ensureNotFinal($job);
            // Close any open visit so the technician is not left with a running timer.
            $job->visits()->where('status', 'in_progress')->get()->each(function ($visit) {
                $visit->update(['status' => 'cancelled', 'end_time' => now(), 'duration_seconds' => $visit->start_time->diffInSeconds(now())]);
            });
            $this->transition($job, JobStatus::Cancelled, $by->id, "Cancelled: $reason");
            $job->update(['cancel_reason' => $reason]);

            return $job;
        });
    }

    /** FR-5.6: reminder / 2nd service call linked to the original job. */
    public function createFollowUp(ServiceJob $parent, array $data, User $by): ServiceJob
    {
        return $this->create(array_merge([
            'customer_id' => $parent->customer_id,
            'customer_product_id' => $parent->customer_product_id,
            'branch_id' => $parent->branch_id,
            'complaint_type_id' => $parent->complaint_type_id,
            'complaint_summary_id' => $parent->complaint_summary_id,
            'product_category' => $parent->product_category,
            'priority' => $parent->priority,
            'call_type' => $parent->call_type,
            'assigned_technician_id' => $parent->assigned_technician_id,
        ], array_filter($data, fn ($v) => $v !== null), ['parent_job_id' => $parent->id]), $by);
    }

    /** A technician can't close a job as cancelled: it waits here, reason attached, until someone who may approve decides. */
    public function requestCancel(ServiceJob $job, string $reason, int $technicianId): void
    {
        $job->cancel_reason = mb_substr($reason, 0, 500);
        $job->cancel_requested_by = $technicianId;
        $job->cancel_requested_at = now();
        $this->transition($job, JobStatus::CancelRequested, $technicianId, "Cancellation requested: $reason");

        $by = User::inTenant($job->tenant_id)->find($technicianId)?->name ?? 'A technician';
        User::inTenant($job->tenant_id)->whereIn('role', config('permissions')['jobs.approve_cancel'])->where('status', 'active')->get()->each(
            fn (User $approver) => $this->notifications->notifyUser($approver, 'job_cancel_requested', 'Cancellation needs your approval', "{$by} asked to cancel job {$job->crm_call_id}: {$job->cancel_reason}", ['job_id' => $job->id])
        );
    }

    public function approveCancel(ServiceJob $job, string $reason, User $by): ServiceJob
    {
        $this->ensureAwaitingCancel($job);
        $job->cancel_reason = $reason;
        $this->transition($job, JobStatus::Cancelled, $by->id, "Cancellation approved: $reason");
        $this->notifyCancelRequester($job, 'job_cancel_approved', 'Cancellation approved', "Job {$job->crm_call_id} has been cancelled.");

        return $job;
    }

    /** Not cancelled after all: the job goes back to waiting for a visit, and the technician is told why. */
    public function rejectCancel(ServiceJob $job, string $note, User $by): ServiceJob
    {
        $this->ensureAwaitingCancel($job);
        $this->notifyCancelRequester($job, 'job_cancel_rejected', 'Cancellation not approved', "Job {$job->crm_call_id} stays open: {$note}");
        $job->cancel_reason = null;
        $job->cancel_requested_by = null;
        $job->cancel_requested_at = null;
        $this->transition($job, JobStatus::Pending, $by->id, "Cancellation rejected: $note");
        // The office has just said the work goes ahead: that is the go-ahead for the next visit.
        $this->grantResume($job, $by);

        return $job;
    }

    /** The technician asks to go back to a job they left pending. It stays assigned to them meanwhile. */
    public function requestResume(ServiceJob $job, User $technician, ?string $note): ServiceJob
    {
        if ($job->assigned_technician_id !== $technician->id) {
            throw ValidationException::withMessages(['job' => 'This job is not assigned to you.']);
        }
        if (! $job->needs_resume_approval) {
            throw ValidationException::withMessages(['job' => 'This job doesn’t need approval. You can start it.']);
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
        $job->forceFill(['resume_requested_at' => now(), 'resume_request_note' => $note])->save();
        $this->history($job, $job->status, $technician->id, 'Asked to resume the job'.($note ? ": $note" : ''));

        User::inTenant($job->tenant_id)->whereIn('role', config('permissions')['jobs.approve_resume'])->where('status', 'active')->get()->each(
            fn (User $approver) => $this->notifications->notifyUser($approver, 'job_resume_requested', 'Job waiting to resume', "{$technician->name} asked to resume job {$job->crm_call_id}".($note ? ": $note" : '.'), ['job_id' => $job->id])
        );

        return $job;
    }

    /** The office lets the technician start the next visit. Approving without a request is fine too. */
    public function approveResume(ServiceJob $job, User $by, ?string $note = null): ServiceJob
    {
        if (! $job->needs_resume_approval) {
            throw ValidationException::withMessages(['status' => 'This job isn’t waiting for approval to resume.']);
        }
        $this->grantResume($job, $by);
        $this->history($job, $job->status, $by->id, 'Approved to resume'.($note ? ": $note" : ''));
        $this->notifyResume($job, 'job_resume_approved', 'Job approved to resume', "You can start job {$job->crm_call_id} again.".($note ? " $note" : ''));

        return $job;
    }

    /** Not yet: the request is cleared and the technician told why. The job stays pending with them. */
    public function rejectResume(ServiceJob $job, string $note, User $by): ServiceJob
    {
        if (! $job->needs_resume_approval) {
            throw ValidationException::withMessages(['status' => 'This job isn’t waiting for approval to resume.']);
        }
        $job->forceFill(['resume_requested_at' => null, 'resume_request_note' => null])->save();
        $this->history($job, $job->status, $by->id, "Resume not approved: $note");
        $this->notifyResume($job, 'job_resume_rejected', 'Job not approved to resume', "Job {$job->crm_call_id}: {$note}");

        return $job;
    }

    /** The go-ahead for one more visit. Starting that visit uses it up. */
    private function grantResume(ServiceJob $job, User $by): void
    {
        $job->forceFill(['resume_approved_at' => now(), 'resume_approved_by' => $by->id, 'resume_requested_at' => null, 'resume_request_note' => null])->save();
    }

    private function notifyResume(ServiceJob $job, string $type, string $title, string $message): void
    {
        if ($job->assigned_technician_id && $tech = User::inTenant($job->tenant_id)->find($job->assigned_technician_id)) {
            $this->notifications->notifyUser($tech, $type, $title, $message, ['job_id' => $job->id]);
        }
    }

    private function notifyCancelRequester(ServiceJob $job, string $type, string $title, string $message): void
    {
        if ($job->cancel_requested_by && $tech = User::inTenant($job->tenant_id)->find($job->cancel_requested_by)) {
            $this->notifications->notifyUser($tech, $type, $title, $message, ['job_id' => $job->id]);
        }
    }

    private function ensureAwaitingCancel(ServiceJob $job): void
    {
        if ($job->status !== JobStatus::CancelRequested) {
            throw ValidationException::withMessages(['status' => 'This job is not waiting for a cancellation decision.']);
        }
    }

    /** Parks the job until a part arrives. Saving again on a part-pending job just corrects the note. */
    public function markPartPending(ServiceJob $job, string $note, User $by): ServiceJob
    {
        $this->ensureNotFinal($job);
        if ($job->activeVisit()->exists()) {
            throw ValidationException::withMessages(['status' => 'A visit is in progress. The technician marks the part as pending when closing it.']);
        }
        $job->part_pending_note = $note;
        $this->transition($job, JobStatus::PartPending, $by->id, "Part pending: $note");

        return $job;
    }

    /** The part has arrived: the job goes back to waiting for a visit and the technician is told. */
    public function partReceived(ServiceJob $job, User $by): ServiceJob
    {
        if ($job->status !== JobStatus::PartPending) {
            throw ValidationException::withMessages(['status' => 'This job is not waiting for a part.']);
        }
        $this->transition($job, $job->visits()->exists() ? JobStatus::Pending : JobStatus::Open, $by->id, 'Part received');
        $this->grantResume($job, $by);

        if ($job->assigned_technician_id && $tech = User::inTenant()->find($job->assigned_technician_id)) {
            $this->notifications->notifyUser($tech, 'part_received', 'Part received', "The part for job {$job->crm_call_id} has arrived. The job is ready for a visit.", ['job_id' => $job->id]);
        }

        return $job;
    }

    public function transition(ServiceJob $job, JobStatus $to, ?int $userId, ?string $remarks = null): void
    {
        if ($job->status !== $to && ! $job->status->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => "Cannot move a job from {$job->status->value} to {$to->value}."]);
        }
        if ($to === JobStatus::PartPending) {
            $job->part_pending_at ??= now();
        } elseif ($job->status === JobStatus::PartPending) {
            $job->part_pending_note = null;
            $job->part_pending_at = null;
        }
        $job->status = $to;
        if ($to === JobStatus::Completed) {
            $job->completed_at = now();
        }
        if ($to === JobStatus::Cancelled) {
            $job->cancelled_at = now();
        }
        $job->save();
        $this->history($job, $to, $userId, $remarks);
    }

    public function history(ServiceJob $job, JobStatus $status, ?int $userId, ?string $remarks): void
    {
        JobStatusHistory::create([
            'job_id' => $job->id,
            'status' => $status->value,
            'changed_by' => $userId,
            'remarks' => $remarks ? mb_substr($remarks, 0, 500) : null,
            'changed_at' => now(),
        ]);
    }

    /** A dated update on the job (what was found, what the customer said). Keeps the job's status. */
    public function addNote(ServiceJob $job, string $note, User $by): JobStatusHistory
    {
        return JobStatusHistory::create([
            'job_id' => $job->id,
            'status' => 'note',
            'changed_by' => $by->id,
            'remarks' => mb_substr($note, 0, 500),
            'changed_at' => now(),
        ])->load('user:id,name');
    }

    private function notifyAssignment(ServiceJob $job, string $title): void
    {
        $tech = User::inTenant($job->tenant_id)->find($job->assigned_technician_id);
        if ($tech) {
            $job->loadMissing('customer');
            $this->notifications->notifyUser($tech, 'job_assigned', $title,
                "{$job->crm_call_id} · {$job->customer?->name} · priority {$job->priority}", ['job_id' => $job->id]);
        }
    }

    private function technicianOrFail(int $id): User
    {
        $tech = User::inTenant()->where('role', Role::Technician->value)->where('status', 'active')->find($id);
        if (! $tech) {
            throw ValidationException::withMessages(['assigned_technician_id' => 'Select an active technician from your company.']);
        }

        return $tech;
    }

    private function ensureNotFinal(ServiceJob $job): void
    {
        if ($job->status->isFinal()) {
            throw ValidationException::withMessages(['status' => 'This job is already '.$job->status->value.'.']);
        }
        if ($job->status === JobStatus::CancelRequested) {
            throw ValidationException::withMessages(['status' => 'The technician asked to cancel this job. That must be approved or rejected first.']);
        }
    }
}
