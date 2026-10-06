<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'payroll_run_id', 'user_id', 'days_in_month', 'working_days', 'present_days', 'paid_leave_days', 'unpaid_leave_days',
        'absent_days', 'holidays', 'weekly_offs', 'lop_days', 'gross', 'earnings', 'deductions', 'lop_amount', 'bonus',
        'other_deduction', 'net_pay', 'note', 'expense_id',
    ];

    protected function casts(): array
    {
        return [
            'working_days' => 'float',
            'present_days' => 'float',
            'paid_leave_days' => 'float',
            'unpaid_leave_days' => 'float',
            'absent_days' => 'float',
            'holidays' => 'float',
            'weekly_offs' => 'float',
            'lop_days' => 'float',
            'gross' => 'integer',
            'earnings' => 'array',
            'deductions' => 'array',
            'lop_amount' => 'integer',
            'bonus' => 'integer',
            'other_deduction' => 'integer',
            'net_pay' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function totalDeductions(): int
    {
        return (int) array_sum(array_column($this->deductions ?? [], 'amount')) + $this->lop_amount + $this->other_deduction;
    }

    /** Net = gross + bonus − fixed deductions − loss of pay − other deductions, never below zero. */
    public function recalculate(): void
    {
        $this->net_pay = max(0, $this->gross + $this->bonus - $this->totalDeductions());
    }
}
