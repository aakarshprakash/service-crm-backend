<?php

namespace App\Services;

use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;

/**
 * Picks the technician for a job automatically: among active technicians who cover the
 * job's service location, the one with the fewest open jobs — skipping anyone already at
 * the company's limit (setting `auto_assign_max_jobs`).
 */
class AutoAssigner
{
    public const ACTIVE_STATUSES = ['open', 'in_progress', 'pending'];

    /**
     * @return array{technician: ?User, open_jobs: ?int, reason: ?string}
     */
    public function pick(ServiceJob $job): array
    {
        $tenant = Tenant::findOrFail($job->tenant_id);
        $max = max(1, (int) $tenant->setting('auto_assign_max_jobs', 10));
        $onDutyOnly = (bool) $tenant->setting('auto_assign_on_duty_only', false);

        $pool = User::inTenant($job->tenant_id)->technicians()->where('status', 'active')
            // With a location: only technicians linked to it. Without one: the job's branch, if any.
            ->when($job->service_location_id, fn ($q) => $q->whereHas('serviceLocations', fn ($l) => $l->whereKey($job->service_location_id)))
            ->when(! $job->service_location_id && $job->branch_id, fn ($q) => $q->where('branch_id', $job->branch_id));

        if (! (clone $pool)->exists()) {
            return ['technician' => null, 'open_jobs' => null, 'reason' => $job->service_location_id
                ? 'No active technician is linked to this service location.'
                : 'No active technician found for this branch.'];
        }

        $available = (clone $pool)->when($onDutyOnly, fn ($q) => $q->where('punch_status', 'in'));
        if ($onDutyOnly && ! (clone $available)->exists()) {
            return ['technician' => null, 'open_jobs' => null, 'reason' => 'No technician for this location is on duty (punched in) right now.'];
        }

        $candidates = $available
            ->withCount(['assignedJobs as open_jobs' => fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES)])
            ->get()
            ->filter(fn (User $u) => $u->open_jobs < $max)
            // Fewest open jobs first; on a tie prefer someone on duty, then a stable order.
            ->sortBy([['open_jobs', 'asc'], fn ($a, $b) => ($b->punch_status === 'in') <=> ($a->punch_status === 'in'), ['id', 'asc']])
            ->values();

        if ($candidates->isEmpty()) {
            return ['technician' => null, 'open_jobs' => null, 'reason' => "Every technician for this location already has {$max} or more open jobs (the auto-assign limit)."];
        }

        $tech = $candidates->first();

        return ['technician' => $tech, 'open_jobs' => (int) $tech->open_jobs, 'reason' => null];
    }
}
