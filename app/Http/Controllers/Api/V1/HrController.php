<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AttendanceAdjustment;
use App\Models\EmployeeProfile;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PunchLog;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\PayrollService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HR module (v2.1): attendance (geo-fenced punches + register), leave and payroll.
 * Routes under /my/* are self-service for every staff member, technicians included.
 */
class HrController extends Controller
{
    public function __construct(private AttendanceService $attendance, private LeaveService $leaves, private PayrollService $payroll) {}

    // ---- Self service ------------------------------------------------------

    public function punch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'source' => ['nullable', Rule::in(['web', 'mobile'])],
        ]);
        $log = $this->attendance->punch($request->user(), $data);
        $user = $request->user();
        $message = $data['type'] === 'in' ? 'Punched in. Have a great day!' : 'Punched out.';
        if ($log->within_fence === false) {
            $message .= " You were {$log->distance_m} m from the office; this punch is flagged.";
        }

        return $this->ok([
            'punch_status' => $user->punch_status, 'punched_at' => $user->punched_at,
            'within_fence' => $log->within_fence, 'distance_m' => $log->distance_m,
        ], $message);
    }

    public function myAttendance(Request $request): JsonResponse
    {
        [$from, $to, $month] = $this->month($request);
        $user = User::inTenant()->findOrFail($request->user()->id);
        $days = $this->attendance->days($this->tenant(), collect([$user]), $from, $to)[$user->id];

        return $this->ok([
            'month' => $month,
            'days' => collect($days)->map(fn ($d, $date) => ['date' => $date] + $d)->values(),
            'summary' => $this->attendance->summarize($days),
            'punch_status' => $user->punch_status,
            'punched_at' => $user->punched_at,
        ]);
    }

    public function myLeaves(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: (int) CarbonImmutable::now($this->tenant()->timezone)->year;
        $requests = LeaveRequest::with(['type:id,name,code', 'decider:id,name'])->where('user_id', $request->user()->id)
            ->orderByDesc('from_date')->limit(100)->get();

        return $this->ok(['year' => $year, 'balances' => $this->leaves->balances($request->user(), $year), 'requests' => $requests]);
    }

    public function applyLeave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('tenant_id', app(TenantContext::class)->id())->where('is_active', true)],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'half_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        abort_if(CarbonImmutable::parse($data['from_date'])->diffInDays(CarbonImmutable::parse($data['to_date'])) > 90, 422, 'A single request can cover at most 90 days.');

        return $this->created($this->leaves->apply($request->user(), $data)->load('type:id,name,code'), 'Leave request sent for approval.');
    }

    public function cancelLeave(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::where('user_id', $request->user()->id)->findOrFail($id);
        $today = CarbonImmutable::now($this->tenant()->timezone)->toDateString();
        $cancellable = $leave->status === 'pending' || ($leave->status === 'approved' && $leave->from_date->toDateString() > $today);
        if (! $cancellable) {
            throw ValidationException::withMessages(['status' => 'Only pending leave, or approved leave that hasn’t started, can be cancelled.']);
        }
        $leave->update(['status' => 'cancelled']);

        return $this->ok($leave, 'Leave cancelled.');
    }

    public function myPayslips(Request $request): JsonResponse
    {
        $slips = Payslip::with('run:id,month,status,paid_at')->where('user_id', $request->user()->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))
            ->orderByDesc('id')->limit(36)->get();

        return $this->ok($slips);
    }

    public function myPayslipPdf(Request $request, int $id): Response
    {
        $slip = Payslip::where('user_id', $request->user()->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['finalized', 'paid']))->findOrFail($id);

        return $this->payroll->payslipPdf($slip)->download("payslip-{$slip->run->month}.pdf");
    }

    // ---- Attendance (managers) ------------------------------------------------

    /** Who is in today: one row per active staff member for a date. */
    public function daily(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'branch_id' => ['nullable', 'integer']]);
        $tenant = $this->tenant();
        $date = $request->input('date') ?? CarbonImmutable::now($tenant->timezone)->toDateString();
        $users = $this->staff($request);
        $days = $this->attendance->days($tenant, $users, $date, $date);
        [$start, $end] = [CarbonImmutable::parse($date, $tenant->timezone)->startOfDay()->utc(), CarbonImmutable::parse($date, $tenant->timezone)->endOfDay()->utc()];
        $punches = PunchLog::whereIn('user_id', $users->pluck('id'))->whereBetween('created_at', [$start, $end])->orderBy('created_at')
            ->get(['id', 'user_id', 'type', 'lat', 'lng', 'accuracy', 'distance_m', 'within_fence', 'source', 'created_at'])->groupBy('user_id');

        $rows = $users->map(fn (User $u) => [
            'user' => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'branch' => $u->branch?->name, 'punch_status' => $u->punch_status],
            'day' => $days[$u->id][$date],
            'punches' => $punches[$u->id] ?? [],
        ])->values();
        $count = fn (array $statuses) => $rows->filter(fn ($r) => in_array($r['day']['status'], $statuses, true))->count();

        return $this->ok($rows, '', 200, [
            'date' => $date,
            'present' => $count(['present', 'half_day']),
            'on_leave' => $count(['leave', 'unpaid_leave']),
            'absent' => $count(['absent']),
            'on_duty_now' => $users->where('punch_status', 'in')->count(),
            'outside_fence' => $rows->filter(fn ($r) => $r['day']['outside'])->count(),
        ]);
    }

    /** Monthly register: staff × days with status codes and totals. */
    public function register(Request $request): JsonResponse
    {
        [$from, $to, $month] = $this->month($request);
        $users = $this->staff($request);
        $days = $this->attendance->days($this->tenant(), $users, $from, $to);

        return $this->ok([
            'month' => $month,
            'dates' => array_keys($days[$users->first()?->id] ?? []),
            'rows' => $users->map(fn (User $u) => [
                'user' => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value, 'branch' => $u->branch?->name],
                'days' => collect($days[$u->id])->map(fn ($d) => ['code' => $d['code'], 'status' => $d['status'], 'in' => $d['in'], 'out' => $d['out'], 'minutes' => $d['minutes'], 'outside' => $d['outside'], 'adjusted' => $d['adjusted'], 'note' => $d['note'] ?? $d['leave_type'] ?? $d['holiday']]),
                'summary' => $this->attendance->summarize($days[$u->id]),
            ])->values(),
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now($this->tenant()->timezone)->toDateString()],
            'status' => ['nullable', Rule::in(AttendanceAdjustment::STATUSES)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $this->assertNotPaid($data['date']);
        if (empty($data['status'])) {
            AttendanceAdjustment::where('user_id', $data['user_id'])->whereDate('date', $data['date'])->delete();

            return $this->ok(null, 'Manual entry removed; attendance follows punches again.');
        }
        $adj = AttendanceAdjustment::updateOrCreate(
            ['user_id' => $data['user_id'], 'date' => $data['date']],
            ['status' => $data['status'], 'note' => $data['note'] ?? null, 'created_by' => $request->user()->id],
        );

        return $this->ok($adj, 'Attendance updated.');
    }

    // ---- Leave (approvers) -----------------------------------------------------

    public function leaveRequests(Request $request): JsonResponse
    {
        $rows = LeaveRequest::with(['user:id,name,role', 'type:id,name,code', 'decider:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('to_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('from_date', '<=', $request->input('to')))
            ->orderByRaw("status = 'pending' desc")->orderByDesc('from_date')
            ->paginate($this->perPage($request));

        return $this->paginated($rows, null, ['pending' => LeaveRequest::where('status', 'pending')->count()]);
    }

    public function decideLeave(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ], ['note.required_if' => 'Give a reason for rejecting.']);
        $leave = $this->leaves->decide(LeaveRequest::findOrFail($id), $data['status'], $data['note'] ?? null, $request->user());

        return $this->ok($leave->load(['user:id,name', 'type:id,name', 'decider:id,name']), 'Leave '.$data['status'].'.');
    }

    public function leaveBalances(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: (int) CarbonImmutable::now($this->tenant()->timezone)->year;
        $rows = $this->staff($request)->map(fn (User $u) => [
            'user' => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value],
            'balances' => $this->leaves->balances($u, $year),
        ])->values();

        return $this->ok(['year' => $year, 'rows' => $rows]);
    }

    // ---- Employees -------------------------------------------------------------

    public function employees(Request $request): JsonResponse
    {
        $users = User::inTenant()->where('role', '!=', Role::Customer->value)->where('status', '!=', 'invited')
            ->with(['branch:id,name', 'employeeProfile'])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', $this->like($request->string('search'))))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->orderBy('name')->paginate($this->perPage($request, 50));

        return $this->paginated($users, fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'role' => $u->role->value,
            'status' => $u->status, 'branch' => $u->branch?->name, 'profile' => $u->employeeProfile,
        ], ['monthly_payroll' => (int) EmployeeProfile::whereNull('date_of_leaving')->sum('monthly_salary')]);
    }

    public function employee(int $userId): JsonResponse
    {
        $user = User::inTenant()->where('role', '!=', Role::Customer->value)->with(['branch:id,name', 'employeeProfile'])->findOrFail($userId);

        return $this->ok([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $user->role->value, 'branch' => $user->branch?->name],
            'profile' => $user->employeeProfile,
            'balances' => $this->leaves->balances($user, (int) CarbonImmutable::now($this->tenant()->timezone)->year),
        ]);
    }

    public function updateEmployee(Request $request, int $userId): JsonResponse
    {
        $user = User::inTenant()->where('role', '!=', Role::Customer->value)->findOrFail($userId);
        $data = $request->validate([
            'employee_code' => ['nullable', 'string', 'max:30'],
            'designation' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'date_of_joining' => ['nullable', 'date'],
            'date_of_leaving' => ['nullable', 'date', 'after_or_equal:date_of_joining'],
            'monthly_salary' => ['required', 'integer', 'min:0', 'max:100000000'],
            'components' => ['nullable', 'array', 'max:20'],
            'components.*.name' => ['required', 'string', 'max:60'],
            'components.*.type' => ['required', Rule::in(['earning', 'deduction'])],
            'components.*.amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'weekly_offs' => ['nullable', 'array', 'max:6'],
            'weekly_offs.*' => ['integer', 'between:1,7'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'ifsc' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'],
            'uan' => ['nullable', 'string', 'max:30'],
        ]);
        $earnings = collect($data['components'] ?? [])->where('type', 'earning')->sum('amount');
        if ($earnings > $data['monthly_salary']) {
            throw ValidationException::withMessages(['components' => 'Earning components add up to more than the monthly gross salary.']);
        }
        $profile = EmployeeProfile::updateOrCreate(['user_id' => $user->id], $data + ['weekly_offs' => $data['weekly_offs'] ?? [7]]);

        return $this->ok($profile, 'Employee details saved.');
    }

    // ---- Payroll -----------------------------------------------------------------

    public function payrollRuns(Request $request): JsonResponse
    {
        return $this->paginated(PayrollRun::withCount('payslips')->with('creator:id,name')->orderByDesc('month')->paginate($this->perPage($request)));
    }

    public function generatePayroll(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $run = $this->payroll->generate($this->tenant(), $data['month'], $request->user());

        return $this->created($run, 'Payroll draft created for '.CarbonImmutable::parse($data['month'].'-01')->format('F Y').'. Review it, then finalize.');
    }

    public function payrollRun(int $id): JsonResponse
    {
        $run = PayrollRun::with(['creator:id,name', 'payslips' => fn ($q) => $q->with('user:id,name,role,branch_id', 'user.branch:id,name')->orderBy('id')])->findOrFail($id);

        return $this->ok($run);
    }

    public function adjustPayslip(Request $request, int $id, int $slipId): JsonResponse
    {
        $data = $request->validate([
            'bonus' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'other_deduction' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'lop_days' => ['sometimes', 'numeric', 'min:0', 'max:31'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $slip = Payslip::where('payroll_run_id', $id)->findOrFail($slipId);
        if (array_key_exists('lop_days', $data)) {
            // Manual LOP override (e.g. a late approval) – re-price it at the same daily rate.
            $data['lop_amount'] = min($slip->gross, (int) round($slip->gross / $slip->days_in_month * $data['lop_days']));
        }

        return $this->ok($this->payroll->adjust($slip, $data), 'Payslip updated.');
    }

    public function finalizePayroll(int $id): JsonResponse
    {
        return $this->ok($this->payroll->finalize(PayrollRun::findOrFail($id)), 'Payroll finalized. Staff can now see their payslips.');
    }

    public function payPayroll(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(Expense::METHODS)],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now($this->tenant()->timezone)->toDateString()],
        ]);
        $run = $this->payroll->pay(PayrollRun::findOrFail($id), $data['payment_method'], $data['paid_on'], $request->user());

        return $this->ok($run, 'Salaries marked as paid and added to expenses.');
    }

    public function deletePayroll(int $id): JsonResponse
    {
        $run = PayrollRun::findOrFail($id);
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft payroll can be deleted.']);
        }
        $run->delete();

        return $this->ok(null, 'Draft payroll deleted.');
    }

    public function payslipPdf(int $id): Response
    {
        $slip = Payslip::findOrFail($id);

        return $this->payroll->payslipPdf($slip)->download("payslip-{$slip->run->month}-{$slip->user_id}.pdf");
    }

    // ---- Helpers ---------------------------------------------------------------

    private function tenant()
    {
        return app(TenantContext::class)->tenant();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function month(Request $request): array
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->input('month') ?? CarbonImmutable::now($this->tenant()->timezone)->format('Y-m');
        $start = CarbonImmutable::parse($month.'-01');

        return [$start->toDateString(), $start->endOfMonth()->toDateString(), $month];
    }

    /** Active staff (technicians included) for attendance views. */
    private function staff(Request $request): Collection
    {
        return User::inTenant()->where('role', '!=', Role::Customer->value)->where('status', 'active')->with('branch:id,name')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('id', $request->integer('user_id')))
            ->orderBy('name')->get();
    }

    /** Attendance of a month that has been paid is locked. */
    private function assertNotPaid(string $date): void
    {
        if (PayrollRun::where('month', substr($date, 0, 7))->where('status', '!=', 'draft')->exists()) {
            throw ValidationException::withMessages(['date' => 'Payroll for this month is finalized, so its attendance is locked.']);
        }
    }
}
