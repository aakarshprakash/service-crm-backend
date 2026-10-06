<?php

namespace App\Services;

use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(private AttendanceService $attendance, private NotificationService $notifications) {}

    /** Leave days a request would use: working days only (weekly offs and holidays are not counted). */
    public function countDays(User $user, string $from, string $to, bool $halfDay): float
    {
        if ($halfDay) {
            return 0.5;
        }
        $offs = EmployeeProfile::where('user_id', $user->id)->first()?->weeklyOffs() ?? [7];

        return $this->attendance->workingDays($from, $to, $offs);
    }

    /**
     * @param  array{leave_type_id: int, from_date: string, to_date: string, half_day?: bool, reason?: ?string}  $data
     */
    public function apply(User $user, array $data): LeaveRequest
    {
        $half = (bool) ($data['half_day'] ?? false);
        if ($half && $data['from_date'] !== $data['to_date']) {
            throw ValidationException::withMessages(['half_day' => 'A half-day leave must start and end on the same day.']);
        }
        $days = $this->countDays($user, $data['from_date'], $data['to_date'], $half);
        if ($days <= 0) {
            throw ValidationException::withMessages(['to_date' => 'These dates are all weekly offs or holidays – no leave needed.']);
        }

        return DB::transaction(function () use ($user, $data, $half, $days) {
            $overlap = LeaveRequest::where('user_id', $user->id)->whereIn('status', ['pending', 'approved'])
                ->where('from_date', '<=', $data['to_date'])->where('to_date', '>=', $data['from_date'])->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['from_date' => 'You already have leave on some of these dates.']);
            }
            $type = LeaveType::findOrFail($data['leave_type_id']);
            if ($type->annual_quota > 0) {
                $year = (int) substr($data['from_date'], 0, 4);
                $left = $type->annual_quota - $this->used($user, $type->id, $year, ['approved', 'pending']);
                if ($days > $left) {
                    throw ValidationException::withMessages(['leave_type_id' => "Only {$this->fmt($left)} day(s) of {$type->name} left this year (including pending requests)."]);
                }
            }

            $request = LeaveRequest::create([
                'user_id' => $user->id, 'leave_type_id' => $type->id, 'from_date' => $data['from_date'], 'to_date' => $data['to_date'],
                'half_day' => $half, 'days' => $days, 'reason' => $data['reason'] ?? null, 'status' => 'pending',
            ]);

            DB::afterCommit(fn () => $this->notifications->notifyRoles(['admin', 'coordinator'], 'leave_requested', 'Leave request',
                "{$user->name} asked for {$this->fmt($days)} day(s) of {$type->name} from ".CarbonImmutable::parse($data['from_date'])->format('d M').'.',
                ['leave_request_id' => $request->id]));

            return $request;
        });
    }

    public function decide(LeaveRequest $request, string $status, ?string $note, User $by): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request has already been '.$request->status.'.']);
        }
        if ($request->user_id === $by->id) {
            throw ValidationException::withMessages(['status' => 'Someone else must approve your own leave.']);
        }
        $request->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
        $request->loadMissing('type:id,name', 'user');

        $this->notifications->notifyUser($request->user, 'leave_decided', 'Leave '.$status,
            "Your {$request->type?->name} from {$request->from_date->format('d M')} to {$request->to_date->format('d M')} was {$status}.".($note ? " Note: $note" : ''),
            ['leave_request_id' => $request->id]);

        return $request;
    }

    public function used(User $user, int $typeId, int $year, array $statuses = ['approved']): float
    {
        return (float) LeaveRequest::where('user_id', $user->id)->where('leave_type_id', $typeId)->whereIn('status', $statuses)
            ->whereYear('from_date', $year)->sum('days');
    }

    /** Per leave type: quota, used (approved), pending and remaining for a year. */
    public function balances(User $user, int $year): array
    {
        $rows = LeaveRequest::where('user_id', $user->id)->whereYear('from_date', $year)->whereIn('status', ['approved', 'pending'])
            ->selectRaw('leave_type_id, status, SUM(days) as d')->groupBy('leave_type_id', 'status')->get()
            ->groupBy('leave_type_id');

        return LeaveType::where('is_active', true)->orderBy('name')->get()->map(function (LeaveType $t) use ($rows) {
            $byStatus = ($rows[$t->id] ?? collect())->pluck('d', 'status');
            $used = (float) ($byStatus['approved'] ?? 0);
            $pending = (float) ($byStatus['pending'] ?? 0);

            return [
                'leave_type' => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'is_paid' => $t->is_paid],
                'quota' => $t->annual_quota,
                'used' => $used,
                'pending' => $pending,
                'remaining' => $t->annual_quota > 0 ? max(0, $t->annual_quota - $used - $pending) : null,
            ];
        })->all();
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1), '0'), '.');
    }
}
