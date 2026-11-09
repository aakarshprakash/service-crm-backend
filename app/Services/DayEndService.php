<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Models\JobVisit;
use App\Models\PunchLog;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * End-of-day tidying, each switched on in Settings with a time of day (company time):
 *  - auto punch-out: anyone still punched in at that time is punched out, recorded at that time;
 *  - auto-close: a visit still running at that time is closed as pending with the remark "Auto closed".
 * Runs every few minutes from the scheduler; something that started after the day's time waits for the next day's.
 */
class DayEndService
{
    public function __construct(private JobService $jobs, private NotificationService $notifications) {}

    /** When something that began at $since is cut off: $at on that local day, or the next day if it began later. */
    public static function cutoff(CarbonImmutable $since, string $at, string $tz): CarbonImmutable
    {
        $local = $since->timezone($tz);
        $cut = CarbonImmutable::parse($local->toDateString().' '.$at, $tz);

        return $cut->lte($local) ? $cut->addDay() : $cut;
    }

    /** @return int how many people were punched out */
    public function autoPunchOut(Tenant $tenant): int
    {
        $at = $tenant->setting('attendance.auto_punch_out_at');
        if (! $at) {
            return 0;
        }
        $tz = $tenant->timezone;
        $done = 0;

        User::inTenant($tenant->id)->where('punch_status', 'in')->get()->each(function (User $user) use ($at, $tz, &$done) {
            $in = PunchLog::where('user_id', $user->id)->where('type', 'in')->latest('created_at')->latest('id')->first();
            if (! $in) {
                return;
            }
            $cut = self::cutoff(CarbonImmutable::parse($in->created_at), $at, $tz);
            if ($cut->isFuture()) {
                return;
            }
            DB::transaction(function () use ($user, $in, $cut) {
                $fresh = User::whereKey($user->id)->lockForUpdate()->first();
                if ($fresh?->punch_status !== 'in') {
                    return;
                }
                $log = new PunchLog([
                    'user_id' => $user->id,
                    'type' => 'out',
                    'branch_id' => $in->branch_id,
                    'source' => 'auto',
                    'shift_name' => $in->shift_name,
                    'shift_start' => $in->shift_start,
                    'shift_end' => $in->shift_end,
                    'shift_grace' => $in->shift_grace,
                ]);
                $log->created_at = $cut->utc();
                $log->save();
                $fresh->forceFill(['punch_status' => 'out', 'punched_at' => $cut->utc()])->save();
            });
            $done++;
            $this->notifications->notifyUser($user, 'auto_punch_out', 'Punched out automatically',
                'You were still punched in, so you were punched out at '.$cut->timezone($tz)->format('h:i A').'. Tell the office if your hours were different.');
        });

        return $done;
    }

    /** @return int how many visits were closed */
    public function autoCloseVisits(Tenant $tenant): int
    {
        $at = $tenant->setting('jobs.auto_close_visits_at');
        if (! $at) {
            return 0;
        }
        $tz = $tenant->timezone;
        $done = 0;

        JobVisit::where('status', 'in_progress')->get()->each(function (JobVisit $visit) use ($at, $tz, &$done) {
            $cut = self::cutoff(CarbonImmutable::parse($visit->start_time), $at, $tz);
            if ($cut->isFuture()) {
                return;
            }
            $closed = DB::transaction(function () use ($visit, $cut, $at) {
                $visit = JobVisit::whereKey($visit->id)->lockForUpdate()->first();
                if (! $visit?->isOpen()) {
                    return null;
                }
                $job = ServiceJob::whereKey($visit->job_id)->lockForUpdate()->firstOrFail();
                $end = $cut->utc();
                // Photos, voice notes and parts stay with the visit; nothing is billed until someone finishes the job.
                $visit->fill([
                    'status' => 'pending',
                    'auto_closed' => true,
                    'end_time' => $end,
                    'duration_seconds' => max(0, (int) $visit->start_time->diffInSeconds($end)),
                    'service_summary' => trim(($visit->service_summary ? $visit->service_summary."\n" : '').'Auto closed'),
                ])->save();
                if ($job->status === JobStatus::InProgress) {
                    $this->jobs->transition($job, JobStatus::Pending, null, "Auto closed: the visit was still running at {$at}");
                }

                return $job;
            });
            if (! $closed) {
                return;
            }
            $done++;
            if ($tech = User::inTenant($visit->tenant_id)->find($visit->technician_id)) {
                $this->notifications->notifyUser($tech, 'visit_auto_closed', 'Visit closed automatically',
                    "Your visit on job {$closed->crm_call_id} was still running at ".$cut->timezone($tz)->format('h:i A').', so it was closed as pending. Your photos and parts are kept.', ['job_id' => $closed->id]);
            }
        });

        return $done;
    }
}
