<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AttendanceAdjustment;
use App\Models\Branch;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PunchLog;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Attendance: geo-fenced punch in / out, and the day-by-day status engine that the
 * attendance register, "my attendance" and payroll all share.
 *
 * Day status, first match wins: future · not joined / left · manual adjustment ·
 * approved leave · punched (present) · holiday · weekly off · absent.
 *
 * Shifts add late arrival, early leaving and extra time to a day. They only flag: a late day
 * is still a day present, so payroll is unaffected.
 */
class AttendanceService
{
    /** Status → register code. */
    public const CODES = [
        'present' => 'P', 'half_day' => 'HD', 'absent' => 'A', 'leave' => 'L', 'unpaid_leave' => 'LOP',
        'holiday' => 'H', 'weekly_off' => 'WO', 'not_joined' => 'NJ', 'future' => '',
    ];

    /**
     * @param  array{type: string, lat?: ?float, lng?: ?float, accuracy?: ?float, source?: ?string}  $data
     */
    public function punch(User $user, array $data): PunchLog
    {
        $user->refresh(); // current punch status, even if the instance was loaded partially
        if ($user->punch_status === $data['type']) {
            throw ValidationException::withMessages(['type' => "You are already punched {$data['type']}."]);
        }
        $tenant = Tenant::findOrFail($user->tenant_id);
        $mode = $tenant->setting('attendance.geofence', 'off');
        $applies = $mode !== 'off' && ($user->role !== Role::Technician || $tenant->setting('attendance.geofence_technicians', false));
        $hasLocation = isset($data['lat'], $data['lng']);

        if (! $hasLocation && ($tenant->setting('attendance.require_location', false) || ($applies && $mode === 'enforce'))) {
            throw ValidationException::withMessages(['location' => 'Turn on location (GPS) to punch '.$data['type'].'.']);
        }

        $branch = $user->branch_id ? Branch::find($user->branch_id) : null;
        $distance = null;
        $within = null;
        if ($hasLocation && $branch?->hasGeofence()) {
            $distance = (int) round(Geo::distance((float) $data['lat'], (float) $data['lng'], $branch->lat, $branch->lng));
            // Allow for GPS error: a fix whose accuracy circle overlaps the fence counts as inside.
            $slack = min(100, (float) ($data['accuracy'] ?? 0));
            // Distance is always kept; inside/outside only for people the geofence applies to,
            // so exempt staff (technicians by default) are never flagged.
            $within = $applies ? $distance <= $branch->geofence_radius + $slack : null;
            if ($applies && $mode === 'enforce' && ! $within) {
                throw ValidationException::withMessages(['location' => "You are about {$this->human($distance)} from {$branch->name}. Punch in within {$branch->geofence_radius} m of the office."]);
            }
        }

        // Kept with the punch, so changing a shift later doesn't rewrite this day.
        $shift = $this->shiftFor(EmployeeProfile::with('shift')->where('user_id', $user->id)->first());
        $log = PunchLog::create([
            'shift_name' => $shift?->name,
            'shift_start' => $shift?->start_time,
            'shift_end' => $shift?->end_time,
            'shift_grace' => $shift?->grace_minutes,
            'user_id' => $user->id,
            'type' => $data['type'],
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'branch_id' => $branch?->id,
            'distance_m' => $distance,
            'within_fence' => $within,
            'source' => $data['source'] ?? null,
        ]);
        $user->forceFill(['punch_status' => $data['type'], 'punched_at' => now()])->save();

        return $log;
    }

    /** The shift someone works now: their own, otherwise the company's default. */
    public function shiftFor(?EmployeeProfile $profile, ?Shift $default = null): ?Shift
    {
        $own = $profile?->shift;
        if ($own?->is_active) {
            return $own;
        }

        return $default ?? $this->defaultShift();
    }

    private function defaultShift(): ?Shift
    {
        return Shift::where('is_default', true)->where('is_active', true)->first();
    }

    private function human(int $metres): string
    {
        return $metres >= 1000 ? round($metres / 1000, 1).' km' : $metres.' m';
    }

    /**
     * Day-by-day attendance for users between local dates.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, array{status: string, code: string, in: ?string, out: ?string, minutes: int, outside: bool, leave_type: ?string, adjusted: bool}>>
     */
    public function days(Tenant $tenant, Collection $users, string $from, string $to): array
    {
        $tz = $tenant->timezone;
        $ids = $users->pluck('id');
        $today = CarbonImmutable::now($tz)->toDateString();
        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();

        $punches = PunchLog::whereIn('user_id', $ids)->whereBetween('created_at', [$start, $end])->orderBy('created_at')->get()
            ->groupBy(fn (PunchLog $p) => $p->user_id.'|'.$p->created_at->timezone($tz)->toDateString());
        $adjustments = AttendanceAdjustment::whereIn('user_id', $ids)->whereBetween('date', [$from, $to])->get()
            ->keyBy(fn ($a) => $a->user_id.'|'.$a->date->toDateString());
        $leaves = LeaveRequest::with('type:id,name,is_paid')->whereIn('user_id', $ids)->where('status', 'approved')
            ->where('from_date', '<=', $to)->where('to_date', '>=', $from)->get()->groupBy('user_id');
        $holidays = Holiday::where('is_active', true)->whereBetween('date', [$from, $to])->pluck('name', 'date')
            ->mapWithKeys(fn ($name, $date) => [substr((string) $date, 0, 10) => $name]);
        $profiles = EmployeeProfile::with('shift')->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $defaultShift = $this->defaultShift();

        $out = [];
        foreach ($users as $user) {
            $profile = $profiles[$user->id] ?? null;
            $offs = $profile?->weeklyOffs() ?? [7];
            $joined = $profile?->date_of_joining?->toDateString();
            $left = $profile?->date_of_leaving?->toDateString();
            $userLeaves = $leaves[$user->id] ?? collect();
            $currentShift = $this->shiftFor($profile, $defaultShift);

            foreach (CarbonPeriod::create($from, $to) as $day) {
                $d = $day->format('Y-m-d');
                $dayPunches = $punches[$user->id.'|'.$d] ?? collect();
                [$in, $outAt, $minutes, $outside] = $this->worked($dayPunches, $tz, $d === $today);
                $leave = $userLeaves->first(fn (LeaveRequest $l) => $l->from_date->toDateString() <= $d && $l->to_date->toDateString() >= $d);
                $adj = $adjustments[$user->id.'|'.$d] ?? null;

                $status = match (true) {
                    $d > $today => 'future',
                    ($joined && $d < $joined) || ($left && $d > $left) => 'not_joined',
                    $adj !== null => $adj->status,
                    $leave !== null => $leave->half_day && $dayPunches->isNotEmpty() ? 'half_day' : ($leave->type?->is_paid === false ? 'unpaid_leave' : 'leave'),
                    $dayPunches->isNotEmpty() => 'present',
                    isset($holidays[$d]) => 'holiday',
                    in_array($day->isoWeekday(), $offs, true) => 'weekly_off',
                    default => 'absent',
                };
                // Only a normal working day is judged against the shift.
                $working = $adj === null && $leave === null && ! isset($holidays[$d]) && ! in_array($day->isoWeekday(), $offs, true);
                [$late, $early, $extra] = $this->timing($dayPunches, $d, $tz, $minutes, $working);
                $shiftPunch = $dayPunches->first(fn (PunchLog $p) => $p->shift_start !== null);

                $out[$user->id][$d] = [
                    'late_minutes' => $late,
                    'early_minutes' => $early,
                    'extra_minutes' => $extra,
                    'shift' => $shiftPunch
                        ? ['name' => $shiftPunch->shift_name, 'start' => $shiftPunch->shift_start, 'end' => $shiftPunch->shift_end]
                        : ($currentShift ? ['name' => $currentShift->name, 'start' => $currentShift->start_time, 'end' => $currentShift->end_time] : null),
                    'status' => $status,
                    'code' => self::CODES[$status] ?? '',
                    'in' => $in,
                    'out' => $outAt,
                    'minutes' => $minutes,
                    'outside' => $outside,
                    'leave_type' => $leave?->type?->name,
                    'half_day_leave' => (bool) $leave?->half_day,
                    'holiday' => $holidays[$d] ?? null,
                    'adjusted' => $adj !== null,
                    'note' => $adj?->note,
                ];
            }
        }

        return $out;
    }

    /**
     * Late by, left early by and extra minutes, from the shift kept with the day's punches.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function timing(Collection $punches, string $date, string $tz, int $minutes, bool $working): array
    {
        $first = $punches->firstWhere('type', 'in');
        if (! $first?->shift_start || ! $first->shift_end) {
            return [0, 0, 0];
        }
        // A day off has no hours to keep: whatever was worked is extra.
        if (! $working) {
            return [0, 0, $minutes];
        }
        $start = CarbonImmutable::parse("{$date} {$first->shift_start}", $tz);
        $end = CarbonImmutable::parse("{$date} {$first->shift_end}", $tz);

        $lateBy = (int) floor(($first->created_at->getTimestamp() - $start->getTimestamp()) / 60);
        $last = $punches->last();
        // Still on duty, or back from a break, isn't leaving early.
        $earlyBy = $last->type === 'out' ? (int) floor(($end->getTimestamp() - $last->created_at->getTimestamp()) / 60) : 0;
        $length = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);

        return [$lateBy > (int) $first->shift_grace ? $lateBy : 0, max(0, $earlyBy), max(0, $minutes - $length)];
    }

    /** @return array{0: ?string, 1: ?string, 2: int, 3: bool} first in, last out, minutes worked, any punch outside the fence */
    private function worked(Collection $punches, string $tz, bool $isToday): array
    {
        if ($punches->isEmpty()) {
            return [null, null, 0, false];
        }
        $minutes = 0;
        $openedAt = null;
        foreach ($punches as $p) {
            if ($p->type === 'in') {
                $openedAt ??= $p->created_at;
            } elseif ($openedAt) {
                $minutes += (int) $openedAt->diffInMinutes($p->created_at);
                $openedAt = null;
            }
        }
        if ($openedAt && $isToday) {
            $minutes += (int) $openedAt->diffInMinutes(now()); // still on duty
        }
        $firstIn = $punches->firstWhere('type', 'in');
        $lastOut = $punches->where('type', 'out')->last();

        return [
            $firstIn?->created_at->timezone($tz)->format('H:i'),
            $lastOut?->created_at->timezone($tz)->format('H:i'),
            $minutes,
            $punches->contains(fn ($p) => $p->within_fence === false),
        ];
    }

    /**
     * Totals for a set of days from days().
     *
     * @param  array<string, array{status: string}>  $days
     * @return array{present: float, half_days: int, absent: float, paid_leave: float, unpaid_leave: float, holidays: int, weekly_offs: int, not_joined: int, minutes: int, late_days: int, late_minutes: int, early_days: int, early_minutes: int, extra_minutes: int}
     */
    public function summarize(array $days): array
    {
        $s = ['present' => 0.0, 'half_days' => 0, 'absent' => 0.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'holidays' => 0, 'weekly_offs' => 0, 'not_joined' => 0, 'minutes' => 0, 'late_days' => 0, 'late_minutes' => 0, 'early_days' => 0, 'early_minutes' => 0, 'extra_minutes' => 0];
        foreach ($days as $day) {
            $s['minutes'] += $day['minutes'] ?? 0;
            $s['late_days'] += ($day['late_minutes'] ?? 0) > 0 ? 1 : 0;
            $s['late_minutes'] += $day['late_minutes'] ?? 0;
            $s['early_days'] += ($day['early_minutes'] ?? 0) > 0 ? 1 : 0;
            $s['early_minutes'] += $day['early_minutes'] ?? 0;
            $s['extra_minutes'] += $day['extra_minutes'] ?? 0;
            $halfLeave = ! empty($day['half_day_leave']);
            switch ($day['status']) {
                case 'present': $s['present'] += 1; break;
                case 'half_day':
                    $s['present'] += 0.5;
                    $s['half_days']++;
                    // The other half is the half-day leave if one was approved, else unpaid absence.
                    $halfLeave ? $s['paid_leave'] += 0.5 : $s['absent'] += 0.5;
                    break;
                case 'leave':
                    // A half-day leave with no punch that day: half paid leave, half absent.
                    $s['paid_leave'] += $halfLeave ? 0.5 : 1;
                    $s['absent'] += $halfLeave ? 0.5 : 0;
                    break;
                case 'unpaid_leave': $s['unpaid_leave'] += 1; break;
                case 'holiday': $s['holidays']++; break;
                case 'weekly_off': $s['weekly_offs']++; break;
                case 'not_joined': $s['not_joined']++; break;
                case 'absent': $s['absent'] += 1; break;
            }
        }

        return $s;
    }

    /** Working days in a range for one user's weekly offs, excluding company holidays. */
    public function workingDays(string $from, string $to, array $weeklyOffs): float
    {
        $holidays = Holiday::where('is_active', true)->whereBetween('date', [$from, $to])->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $n = 0;
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if (! in_array($day->isoWeekday(), $weeklyOffs, true) && ! in_array($day->format('Y-m-d'), $holidays, true)) {
                $n++;
            }
        }

        return $n;
    }
}
