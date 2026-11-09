<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StoresUtc;
use App\Services\AutoAssigner;
use App\Support\RichText;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
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
    use Auditable, BelongsToTenant, StoresUtc;

    protected $fillable = [
        'crm_call_id', 'customer_id', 'customer_product_id', 'branch_id', 'service_location_id', 'complaint_type_id', 'complaint_summary_id',
        'complaint_details', 'product_category', 'customer_satisfaction_code', 'priority', 'call_type', 'status', 'service_type', 'scheduled_at', 'assigned_technician_id',
        'created_by', 'parent_job_id', 'completed_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $appends = ['call_age_days', 'needs_resume_approval'];

    /** The customer's share-location link is handed out by the office, never in job payloads. */
    protected $hidden = ['location_token'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'scheduled_at' => 'datetime',
            'part_pending_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'resume_requested_at' => 'datetime',
            'resume_approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Details may arrive as formatted HTML (web editor) or plain text (apps, portal). Formatted input is
     * sanitised into complaint_details_html and complaint_details always holds the plain-text version.
     */
    public function setComplaintDetailsAttribute(?string $value): void
    {
        if ($value !== null && RichText::isHtml($value)) {
            $html = RichText::clean($value);
            $text = RichText::toText($html);
            $this->attributes['complaint_details_html'] = $text === '' ? null : $html;
            $this->attributes['complaint_details'] = $text === '' ? null : mb_substr($text, 0, 5000);

            return;
        }
        $this->attributes['complaint_details_html'] = null;
        $this->attributes['complaint_details'] = $value === null || trim($value) === '' ? null : $value;
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
        // Calendar days where the company is: a job logged at 2 am in India is not "1 day old" at 6 am.
        $tz = app(TenantContext::class)->tenant()?->timezone ?? config('app.timezone');

        return (int) $this->created_at->copy()->timezone($tz)->startOfDay()->diffInDays($end->copy()->timezone($tz)->startOfDay());
    }

    /**
     * Left pending or part pending by a technician: the next visit needs the office's go-ahead
     * (Settings → Technician app). A job that was never visited, e.g. parked for a part before anyone went, doesn't.
     */
    public function getNeedsResumeApprovalAttribute(): bool
    {
        if (! array_key_exists('status', $this->attributes) || ! array_key_exists('resume_approved_at', $this->attributes)) {
            return false;
        }
        if (! in_array($this->status, [JobStatus::Pending, JobStatus::PartPending], true) || $this->resume_approved_at) {
            return false;
        }
        if (! (app(TenantContext::class)->tenant()?->setting('jobs.resume_needs_approval') ?? true)) {
            return false;
        }

        // Lists load `visits_exists` with the jobs, so this doesn't run a query per job.
        if (array_key_exists('visits_exists', $this->attributes)) {
            return (bool) $this->attributes['visits_exists'];
        }

        return $this->relationLoaded('visits') ? $this->visits->isNotEmpty() : $this->visits()->exists();
    }

    /** An unfinished job this many days old counts as an old job (Settings → Preferences). */
    public static function agedDays(): int
    {
        return (int) (app(TenantContext::class)->tenant()?->setting('jobs.age_old') ?? 7);
    }

    /** Created before this moment means at least agedDays() calendar days old where the company is. */
    public static function agedBefore(string $tz): CarbonInterface
    {
        return now($tz)->startOfDay()->subDays(self::agedDays() - 1)->utc();
    }

    /** The Jobs list's one-click filters. An unknown name filters nothing. */
    public function scopeQuick(Builder $query, ?string $name, string $tz): Builder
    {
        $unfinished = AutoAssigner::ACTIVE_STATUSES;

        return match ($name) {
            'unassigned' => $query->whereNull('assigned_technician_id')->whereIn('status', ['open', 'pending']),
            'overdue' => $query->whereIn('status', ['open', 'pending'])->where('scheduled_at', '<', now($tz)->startOfDay()->utc()),
            'today' => $query->whereBetween('scheduled_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()]),
            'high' => $query->where('priority', 'high')->whereIn('status', $unfinished),
            'aged' => $query->whereIn('status', $unfinished)->where('created_at', '<', self::agedBefore($tz)),
            'no_location' => $query->whereNull('service_location_id')->whereIn('status', $unfinished),
            default => $query,
        };
    }

    /**
     * For the technician's app: completed and cancelled jobs drop out after the days set in Settings → Technician app.
     * They stay on record (reports, the office, the customer's history); 0 days keeps them in the app.
     */
    public function scopeClosedRecently(Builder $query): Builder
    {
        $days = (int) (app(TenantContext::class)->tenant()?->setting('jobs.tech_hide_closed_after_days') ?? 2);
        if ($days <= 0) {
            return $query;
        }
        $since = now()->subDays($days);

        return $query->where(fn (Builder $q) => $q->whereNotIn('status', [JobStatus::Completed->value, JobStatus::Cancelled->value])
            ->orWhere('completed_at', '>=', $since)
            ->orWhere('cancelled_at', '>=', $since));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term = trim((string) $term)) {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like, $term) {
            $q->where('crm_call_id', 'like', $like)
                ->orWhere('product_category', 'like', $like)
                ->orWhere('customer_satisfaction_code', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('phone', 'like', $like)
                    ->orWhere('alt_phone', 'like', $like)
                    ->orWhere('crm_id', $term)
                    ->orWhere('name', 'like', $like))
                ->orWhereHas('customerProduct', fn ($p) => $p->where('serial_no', 'like', $like)
                    ->orWhere('outdoor_serial_no', 'like', $like)
                    ->orWhere('model_no', 'like', $like));
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

    public function cancelRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancel_requested_by');
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
