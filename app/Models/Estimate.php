<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a job will cost, given to the customer before the work is done. The office marks it approved
 * or rejected once the customer answers. It never moves stock or money.
 */
class Estimate extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft', 'sent', 'approved', 'rejected'];

    protected $fillable = [
        'estimate_number', 'customer_id', 'customer_product_id', 'job_id', 'branch_id', 'status', 'estimate_date', 'valid_until',
        'subject', 'notes', 'terms', 'total_service_charge', 'total_spare_charge', 'discount_amount', 'total_amount', 'is_gst',
        'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'place_of_supply', 'customer_gstin', 'gst_breakup',
        'share_token', 'sent_at', 'decided_at', 'decided_by', 'decision_note', 'created_by',
    ];

    protected $hidden = ['share_token'];

    protected $appends = ['is_expired'];

    protected function casts(): array
    {
        return [
            'estimate_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'total_service_charge' => 'integer',
            'total_spare_charge' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'is_gst' => 'boolean',
            'taxable_amount' => 'integer',
            'cgst_amount' => 'integer',
            'sgst_amount' => 'integer',
            'igst_amount' => 'integer',
            'gst_breakup' => 'array',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** Still waiting for the customer's answer after its last valid day. */
    public function getIsExpiredAttribute(): bool
    {
        if (! array_key_exists('valid_until', $this->attributes) || ! $this->valid_until || ! in_array($this->status, ['draft', 'sent'], true)) {
            return false;
        }
        $tz = app(TenantContext::class)->tenant()?->timezone ?? config('app.timezone');

        return $this->valid_until->toDateString() < now($tz)->toDateString();
    }

    /** Draft and sent estimates can still be changed; a decided one is reopened first. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term = trim((string) $term)) {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q->where('estimate_number', 'like', $like)
            ->orWhere('subject', 'like', $like)
            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like))
            ->orWhereHas('job', fn ($j) => $j->where('crm_call_id', 'like', $like)));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function customerProduct(): BelongsTo
    {
        return $this->belongsTo(CustomerProduct::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('position')->orderBy('id');
    }
}
