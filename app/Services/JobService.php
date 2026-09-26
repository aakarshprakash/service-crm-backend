<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Models\JobStatusHistory;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sequence;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobService
{
    public function __construct(private NotificationService $notifications) {}

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
                : Sequence::formatted($tenant->id, 'job', $tenant->setting('job_prefix', 'SC'));
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

    public function assign(ServiceJob $job, int $technicianId, ?string $scheduledAt, User $by): ServiceJob
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
        $this->history($job, $job->status, $by->id, ($reassigned ? 'Reassigned' : 'Assigned')." to {$technician->name}");

        if ($reassigned && $old = User::inTenant()->find($previous)) {
            $this->notifications->notifyUser($old, 'job_reassigned', 'Job reassigned', "Job {$job->crm_call_id} has been reassigned to another technician.", ['job_id' => $job->id]);
        }
        $this->notifyAssignment($job, $reassigned ? 'Job reassigned to you' : 'New job assigned');
        $this->notifications->notifyCustomer($job, 'technician_assigned');

        return $job;
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
            'priority' => $parent->priority,
            'call_type' => $parent->call_type,
            'assigned_technician_id' => $parent->assigned_technician_id,
        ], array_filter($data, fn ($v) => $v !== null), ['parent_job_id' => $parent->id]), $by);
    }

    public function transition(ServiceJob $job, JobStatus $to, ?int $userId, ?string $remarks = null): void
    {
        if ($job->status !== $to && ! $job->status->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => "Cannot move a job from {$job->status->value} to {$to->value}."]);
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
    }
}
