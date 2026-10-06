<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR details for a staff member: job info, salary structure, bank details, weekly offs. */
class EmployeeProfile extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'user_id', 'employee_code', 'designation', 'department', 'date_of_joining', 'date_of_leaving',
        'monthly_salary', 'components', 'weekly_offs', 'bank_name', 'bank_account', 'ifsc', 'pan', 'uan',
    ];

    protected function casts(): array
    {
        return [
            'date_of_joining' => 'date:Y-m-d',
            'date_of_leaving' => 'date:Y-m-d',
            'monthly_salary' => 'integer',
            'components' => 'array',
            'weekly_offs' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ISO weekdays off (7 = Sunday). Defaults to Sunday. */
    public function weeklyOffs(): array
    {
        return array_map('intval', $this->weekly_offs ?? [7]);
    }

    /** @return array{earnings: list<array{name: string, amount: int}>, deductions: list<array{name: string, amount: int}>} */
    public function salaryBreakup(): array
    {
        $earnings = [];
        $deductions = [];
        foreach ($this->components ?? [] as $c) {
            $line = ['name' => (string) $c['name'], 'amount' => (int) $c['amount']];
            ($c['type'] ?? 'earning') === 'deduction' ? $deductions[] = $line : $earnings[] = $line;
        }
        $listed = array_sum(array_column($earnings, 'amount'));
        // Whatever part of the gross isn't itemised is shown as "Basic / other".
        if ($this->monthly_salary > $listed) {
            array_unshift($earnings, ['name' => $listed ? 'Other allowances' : 'Basic salary', 'amount' => $this->monthly_salary - $listed]);
        }

        return compact('earnings', 'deductions');
    }
}
