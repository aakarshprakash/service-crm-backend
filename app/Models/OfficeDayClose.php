<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The office's end-of-day cash count; see OfficeDayCloseService. */
class OfficeDayClose extends Model
{
    use Auditable, BelongsToTenant;

    public const FLOWS = ['counter_receipts', 'other_income', 'from_technicians', 'from_bank', 'cash_expenses', 'to_bank'];

    protected $fillable = [
        'close_date', 'period_from', 'opening_cash', 'counter_receipts', 'other_income', 'from_technicians', 'from_bank',
        'cash_expenses', 'to_bank', 'expected_cash', 'counted_cash', 'difference', 'remarks', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'close_date' => 'date:Y-m-d',
            'period_from' => 'date:Y-m-d',
            'opening_cash' => 'integer',
            'counter_receipts' => 'integer',
            'other_income' => 'integer',
            'from_technicians' => 'integer',
            'from_bank' => 'integer',
            'cash_expenses' => 'integer',
            'to_bank' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'difference' => 'integer',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
