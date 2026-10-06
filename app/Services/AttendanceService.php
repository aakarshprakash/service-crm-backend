<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AttendanceAdjustment;
use App\Models\Branch;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PunchLog;
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
            $within = $distance <= $branch->geofence_radius + $slack;
            if ($applies && $mode === 'enforce' && ! $within) {
                throw ValidationException::withMessages(['location' => "You are about {$this->human($distance)} from {$branch->name}. Punch in within {$branch->geofence_radius} m of the office."]);
            }
        }

        $log = PunchLog::create([
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
        $profiles = EmployeeProfile::whereIn('user_id', $ids)->get()->keyBy('user_id');

        $out = [];
        foreach ($users as $user) {
            $profile = $profiles[$user->id] ?? null;
            $offs = $profile?->weeklyOffs() ?? [7];
            $joined = $profile?->date_of_joining?->toDateString();
            $left = $profile?->date_of_leaving?->toDateString();
            $userLeaves = $leaves[$user->id] ?? collect();

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

                $out[$user->id][$d] = [
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
     * @return array{present: float, half_days: int, absent: float, paid_leave: float, unpaid_leave: float, holidays: int, weekly_offs: int, not_joined: int, minutes: int}
     */
    public function summarize(array $days): array
    {
        $s = ['present' => 0.0, 'half_days' => 0, 'absent' => 0.0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'holidays' => 0, 'weekly_offs' => 0, 'not_joined' => 0, 'minutes' => 0];
        foreach ($days as $day) {
            $s['minutes'] += $day['minutes'] ?? 0;
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
