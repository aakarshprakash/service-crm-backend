<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'month', 'status', 'total_gross', 'total_deductions', 'total_net', 'created_by', 'finalized_at', 'paid_at', 'payment_method',
    ];

    protected function casts(): array
    {
        return [
            'total_gross' => 'integer',
            'total_deductions' => 'integer',
            'total_net' => 'integer',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function refreshTotals(): void
    {
        $slips = $this->payslips()->get();
        $this->update([
            'total_gross' => (int) $slips->sum(fn (Payslip $p) => $p->gross + $p->bonus),
            'total_deductions' => (int) $slips->sum(fn (Payslip $p) => $p->totalDeductions()),
            'total_net' => (int) $slips->sum('net_pay'),
        ]);
    }
}
