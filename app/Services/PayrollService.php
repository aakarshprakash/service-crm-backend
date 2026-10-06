<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll from attendance:
 *   per-day rate = gross ÷ calendar days in the month
 *   loss of pay (LOP) days = absent + unpaid leave + days before joining / after leaving
 *   net = gross + bonus − fixed deductions − LOP − other deductions
 * Weekly offs, holidays and paid leave are paid. Paying a run records one
 * "Salaries & wages" expense per payslip, so salaries show up in the books.
 */
class PayrollService
{
    public function __construct(private AttendanceService $attendance) {}

    public function generate(Tenant $tenant, string $month, User $by): PayrollRun
    {
        $start = CarbonImmutable::parse($month.'-01', $tenant->timezone);
        $end = $start->endOfMonth();
        $today = CarbonImmutable::now($tenant->timezone);
        if ($end->toDateString() > $today->toDateString()) {
            throw ValidationException::withMessages(['month' => 'Payroll can be run once the month is over (from '.$end->format('d M Y').').']);
        }
        if (PayrollRun::where('month', $month)->exists()) {
            throw ValidationException::withMessages(['month' => 'Payroll for '.$start->format('F Y').' already exists. Open it, or delete the draft to start again.']);
        }

        $profiles = EmployeeProfile::where('monthly_salary', '>', 0)
            ->where(fn ($q) => $q->whereNull('date_of_joining')->orWhere('date_of_joining', '<=', $end->toDateString()))
            ->where(fn ($q) => $q->whereNull('date_of_leaving')->orWhere('date_of_leaving', '>=', $start->toDateString()))
            ->get()->keyBy('user_id');
        $users = User::inTenant()->whereIn('id', $profiles->keys())->where('role', '!=', Role::Customer->value)->get();
        if ($users->isEmpty()) {
            throw ValidationException::withMessages(['month' => 'No staff have a monthly salary set. Add salaries under HR → Employees first.']);
        }

        $days = $this->attendance->days($tenant, $users, $start->toDateString(), $end->toDateString());

        return DB::transaction(function () use ($month, $by, $users, $profiles, $days, $start) {
            $run = PayrollRun::create(['month' => $month, 'status' => 'draft', 'created_by' => $by->id]);
            foreach ($users as $user) {
                $this->buildSlip($run, $user, $profiles[$user->id], $days[$user->id], $start->daysInMonth)->save();
            }
            $run->refreshTotals();

            return $run;
        });
    }

    private function buildSlip(PayrollRun $run, User $user, EmployeeProfile $profile, array $days, int $daysInMonth): Payslip
    {
        $s = $this->attendance->summarize($days);
        $lopDays = $s['absent'] + $s['unpaid_leave'] + $s['not_joined'];
        $gross = $profile->monthly_salary;
        $breakup = $profile->salaryBreakup();
        $lop = (int) round($gross / $daysInMonth * $lopDays);

        $slip = new Payslip([
            'payroll_run_id' => $run->id,
            'user_id' => $user->id,
            'days_in_month' => $daysInMonth,
            'working_days' => $daysInMonth - $s['holidays'] - $s['weekly_offs'],
            'present_days' => $s['present'],
            'paid_leave_days' => $s['paid_leave'],
            'unpaid_leave_days' => $s['unpaid_leave'],
            'absent_days' => $s['absent'] + $s['not_joined'],
            'holidays' => $s['holidays'],
            'weekly_offs' => $s['weekly_offs'],
            'lop_days' => $lopDays,
            'gross' => $gross,
            'earnings' => $breakup['earnings'],
            'deductions' => $breakup['deductions'],
            'lop_amount' => min($gross, $lop),
            'bonus' => 0,
            'other_deduction' => 0,
            'net_pay' => 0,
        ]);
        $slip->recalculate();

        return $slip;
    }

    public function adjust(Payslip $slip, array $data): Payslip
    {
        $this->assertDraft($slip->run);
        $slip->fill($data);
        $slip->recalculate();
        $slip->save();
        $slip->run->refreshTotals();

        return $slip;
    }

    public function finalize(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);
        $run->update(['status' => 'finalized', 'finalized_at' => now()]);

        return $run;
    }

    /** Mark salaries paid: one "Salaries & wages" expense per payslip, dated the pay date. */
    public function pay(PayrollRun $run, string $method, string $paidOn, User $by): PayrollRun
    {
        if ($run->status !== 'finalized') {
            throw ValidationException::withMessages(['status' => $run->status === 'paid' ? 'This payroll is already paid.' : 'Finalize the payroll before paying it.']);
        }

        return DB::transaction(function () use ($run, $method, $paidOn, $by) {
            $category = ExpenseCategory::firstOrCreate(['name' => 'Salaries & wages'], ['is_active' => true]);
            $label = CarbonImmutable::parse($run->month.'-01')->format('M Y');
            foreach ($run->payslips()->with('user:id,name,branch_id')->get() as $slip) {
                if ($slip->net_pay <= 0) {
                    continue;
                }
                $expense = Expense::create([
                    'expense_date' => $paidOn, 'expense_category_id' => $category->id, 'amount' => $slip->net_pay,
                    'payment_method' => $method, 'paid_to' => $slip->user?->name, 'description' => "Salary for $label",
                    'branch_id' => $slip->user?->branch_id, 'user_id' => $slip->user_id, 'created_by' => $by->id,
                    'reference_no' => 'PAYROLL-'.$run->month,
                ]);
                $slip->update(['expense_id' => $expense->id]);
            }
            $run->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $method]);

            return $run;
        });
    }

    public function payslipPdf(Payslip $slip): \Barryvdh\DomPDF\PDF
    {
        $slip->loadMissing(['run', 'user.branch']);
        $tenant = Tenant::findOrFail($slip->tenant_id);
        $profile = EmployeeProfile::where('user_id', $slip->user_id)->first();
        $money = fn (int $v) => Money::format($v, $tenant->currency);
        $month = CarbonImmutable::parse($slip->run->month.'-01')->format('F Y');

        return Pdf::loadView('pdf.payslip', compact('slip', 'tenant', 'profile', 'money', 'month'))->setPaper('a4');
    }

    private function assertDraft(PayrollRun $run): void
    {
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'This payroll is '.$run->status.' and can no longer be changed.']);
        }
    }
}
