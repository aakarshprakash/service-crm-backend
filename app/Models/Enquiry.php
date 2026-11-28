<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lead: someone asked about a repair (service) or about buying something (sales). It is followed up
 * until it becomes a job or a quotation (converted), or the customer goes elsewhere (lost).
 */
class Enquiry extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['service', 'sales'];

    public const STATUSES = ['new', 'follow_up', 'converted', 'lost'];

    /** Still being worked on. */
    public const OPEN = ['new', 'follow_up'];

    public const SOURCES = ['phone', 'walk_in', 'whatsapp', 'website', 'referral', 'dealer', 'social_media', 'other'];

    protected $fillable = [
        'enquiry_number', 'type', 'status', 'source', 'customer_id', 'name', 'phone', 'email', 'address', 'city', 'pincode',
        'brand_id', 'product', 'details', 'expected_value', 'follow_up_at', 'assigned_to', 'branch_id', 'job_id', 'estimate_id',
        'lost_reason', 'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expected_value' => 'integer',
            'follow_up_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'job_id');
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(EnquiryNote::class)->latest('id');
    }
}
