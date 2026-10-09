<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer complaint / service call ("job" in the SRS). Named ServiceJob to avoid
 * clashing with Laravel's queue `jobs` table.
 */
class ServiceJob extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'crm_call_id', 'customer_id', 'customer_product_id', 'branch_id', 'service_location_id', 'complaint_type_id', 'complaint_summary_id',
        'complaint_details', 'priority', 'call_type', 'status', 'service_type', 'scheduled_at', 'assigned_technician_id',
        'created_by', 'parent_job_id', 'completed_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $appends = ['call_age_days'];

    /** The customer's share-location link is handed out by the office, never in job payloads. */
    protected $hidden = ['location_token'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** FR-5.5: days since creation (frozen once the job is closed). */
    public function getCallAgeDaysAttribute(): ?int
    {
        // Partial selects (e.g. "job:id,crm_call_id") don't carry the dates: skip.
        foreach (['created_at', 'completed_at', 'cancelled_at'] as $column) {
            if (! array_key_exists($column, $this->attributes)) {
                return null;
            }
        }
        if (! $this->created_at) {
            return 0;
        }
        $end = $this->completed_at ?? $this->cancelled_at ?? now();

        return (int) $this->created_at->copy()->startOfDay()->diffInDays($end->copy()->startOfDay());
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term = trim((string) $term)) {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like, $term) {
            $q->where('crm_call_id', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('phone', 'like', $like)
                    ->orWhere('alt_phone', 'like', $like)
                    ->orWhere('crm_id', $term)
                    ->orWhere('name', 'like', $like))
                ->orWhereHas('customerProduct', fn ($p) => $p->where('serial_no', 'like', $like)
                    ->orWhere('outdoor_serial_no', 'like', $like));
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function customerProduct(): BelongsTo
    {
        return $this->belongsTo(CustomerProduct::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function serviceLocation(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class);
    }

    public function complaintType(): BelongsTo
    {
        return $this->belongsTo(ComplaintType::class);
    }

    public function complaintSummary(): BelongsTo
    {
        return $this->belongsTo(ComplaintSummary::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_job_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_job_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(JobVisit::class, 'job_id')->orderBy('start_time');
    }

    public function activeVisit(): HasOne
    {
        return $this->hasOne(JobVisit::class, 'job_id')->where('status', 'in_progress');
    }

    public function images(): HasMany
    {
        return $this->hasMany(JobImage::class, 'job_id');
    }

    public function voiceNotes(): HasMany
    {
        return $this->hasMany(JobVoiceNote::class, 'job_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(JobStatusHistory::class, 'job_id')->orderBy('changed_at');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'job_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'job_id');
    }

    public function inventoryUsage(): HasMany
    {
        return $this->hasMany(JobInventoryUsage::class, 'job_id');
    }
}
