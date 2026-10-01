<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'job_id', 'source', 'customer_id', 'branch_id', 'invoice_number', 'total_service_charge', 'total_spare_charge', 'discount_amount',
        'total_amount', 'paid_amount', 'balance_amount', 'payment_status', 'is_credit', 'notes', 'created_by', 'pay_token', 'generated_at',
    ];

    protected $hidden = ['pay_token'];

    protected function casts(): array
    {
        return [
            'total_service_charge' => 'integer',
            'total_spare_charge' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'balance_amount' => 'integer',
            'is_credit' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    /** Recalculate paid / balance / status from successful payments (FR-10.5). */
    public function refreshPaymentTotals(): void
    {
        $paid = (int) $this->payments()->where('status', 'success')->sum('amount');
        $this->paid_amount = $paid;
        $this->balance_amount = max(0, $this->total_amount - $paid);
        $this->payment_status = match (true) {
            $this->total_amount > 0 && $paid >= $this->total_amount => 'paid',
            $paid > 0 => 'partial',
            $this->total_amount === 0 => 'paid',
            default => 'unpaid',
        };
        $this->save();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Line items: only walk-in bills have them (job invoices are built from visits). */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function isWalkIn(): bool
    {
        return $this->source === 'walk_in';
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_at');
    }
}
