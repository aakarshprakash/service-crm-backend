<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashClose extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'branch_id', 'technician_id', 'close_date', 'opening_balance', 'total_cash_collected', 'total_cheque_collected',
        'total_digital_collected', 'expected_in_hand', 'amount_confirmed', 'technician_remarks', 'amount_verified',
        'discrepancy_amount', 'discrepancy_remarks', 'total_deposited', 'closing_balance', 'status', 'force_closed',
        'submitted_at', 'submitted_by', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'close_date' => 'date:Y-m-d',
            'opening_balance' => 'integer',
            'total_cash_collected' => 'integer',
            'total_cheque_collected' => 'integer',
            'total_digital_collected' => 'integer',
            'expected_in_hand' => 'integer',
            'amount_confirmed' => 'integer',
            'amount_verified' => 'integer',
            'discrepancy_amount' => 'integer',
            'total_deposited' => 'integer',
            'closing_balance' => 'integer',
            'force_closed' => 'boolean',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** Cash actually held = verified count (or technician's declared amount until verified). */
    public function actualInHand(): int
    {
        return $this->amount_verified ?? $this->amount_confirmed;
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(CashDeposit::class)->orderBy('deposit_date');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
