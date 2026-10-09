<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * An expense a staff member paid in the field (fuel, parking, a part bought locally…).
 * It becomes a real Expense only when the office approves it; "cash_in_hand" claims
 * also reduce the cash the technician must hand over at the daily cash close.
 */
class ExpenseClaim extends Model
{
    use BelongsToTenant;

    public const PAID_FROM = ['cash_in_hand', 'own_money'];

    protected $fillable = [
        'user_id', 'job_id', 'expense_category_id', 'claim_date', 'amount', 'paid_from', 'description', 'receipt_path',
        'status', 'decided_by', 'decided_at', 'decision_note', 'expense_id', 'cash_close_id',
    ];

    protected $hidden = ['receipt_path'];

    protected $appends = ['receipt_url'];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date:Y-m-d',
            'amount' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function getReceiptUrlAttribute(): ?string
    {
        return $this->id && $this->receipt_path
            ? URL::temporarySignedRoute('files.receipt', now()->addMinutes(30), ['claim' => $this->id])
            : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function cashClose(): BelongsTo
    {
        return $this->belongsTo(CashClose::class);
    }
}
