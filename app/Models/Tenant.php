<?php

namespace App\Models;

use App\Casts\SafeEncryptedArray;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'status', 'timezone', 'currency', 'plan_id', 'email', 'phone', 'address',
        'gstin', 'pan', 'state', 'logo_path', 'settings', 'trial_ends_at',
    ];

    protected $hidden = ['gateway_credentials', 'mail_credentials'];

    public const DEFAULT_SETTINGS = [
        'invoice_prefix' => 'INV',
        'job_prefix' => 'SC',
        'receipt_prefix' => 'RCPT',
        // The rest of each number after its letters. Only job numbers may end in a random number:
        // tax invoices must run in consecutive order, and receipts follow them.
        'numbering' => [
            'job' => ['date' => 'ym', 'ending' => 'counter', 'digits' => 5, 'separator' => 'dash'],
            'invoice' => ['date' => 'ym', 'ending' => 'counter', 'digits' => 5, 'separator' => 'dash'],
            'receipt' => ['date' => 'ym', 'ending' => 'counter', 'digits' => 5, 'separator' => 'dash'],
        ],
        'notifications' => ['sms' => false, 'whatsapp' => false, 'push' => true, 'email' => false],
        'online_payments' => false,
        'strict_cash_close' => true,
        'customer_portal' => true,
        'tutorial_mode' => false,
        'auto_assign_max_jobs' => 10,
        'auto_assign_on_duty_only' => false,
        // Customer sign-off: when on, a visit can only be completed after the customer signs.
        // require_punch_in: technicians must be punched in (on duty) to start a visit.
        // age_*: from how many days an unfinished job shows amber, orange ("old") and red in the Jobs list.
        // resume_needs_approval: a job a technician left pending / part pending waits for the office before the next visit.
        // auto_close_visits_at: HH:MM (company time) when visits still running are closed as pending, remark "Auto closed"; null = never.
        // tech_hide_closed_after_days: completed and cancelled jobs leave the technician's app after this many days; 0 = keep them.
        'jobs' => [
            'require_signature' => false, 'require_punch_in' => true, 'age_warning' => 3, 'age_old' => 7, 'age_critical' => 14,
            'resume_needs_approval' => true, 'auto_close_visits_at' => null, 'tech_hide_closed_after_days' => 2,
        ],
        // Geo-fenced attendance: off | flag (record outside punches) | enforce (block them).
        // auto_punch_out_at: HH:MM (company time) when anyone still punched in is punched out; null = never.
        'attendance' => ['geofence' => 'off', 'geofence_technicians' => false, 'require_location' => false, 'auto_punch_out_at' => null],
        // GST (applies once the company GSTIN is set). Prices are GST-inclusive; rates in %.
        // job_bills / walkin_bills: whether a new bill starts as a GST or non-GST bill. Each bill can still be changed.
        'gst' => ['service_sac' => '998719', 'service_rate' => 18, 'part_rate' => 18, 'job_bills' => 'gst', 'walkin_bills' => 'gst'],
        // Printed on invoices, for customers paying by bank transfer.
        'bank' => ['account_name' => null, 'bank_name' => null, 'account_no' => null, 'ifsc' => null, 'branch' => null],
        'invoice_template' => 'classic', // classic | modern
        // Photo sections on a visit, [{key, label}]; null means DEFAULT_PHOTO_TYPES. Companies add their own, no app update needed.
        'photo_types' => null,
        // Every section name used, by key: photos in a section removed later still show its name.
        'photo_labels' => [],
    ];

    public const DEFAULT_PHOTO_TYPES = [
        ['key' => 'bill', 'label' => 'Bill'],
        ['key' => 'serial', 'label' => 'Serial number'],
        ['key' => 'complaint_part', 'label' => 'Faulty part'],
        ['key' => 'new_part', 'label' => 'New part'],
        ['key' => 'other', 'label' => 'Other'],
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'gateway_credentials' => SafeEncryptedArray::class,
            'mail_credentials' => SafeEncryptedArray::class,
            'trial_ends_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get(array_replace_recursive(self::DEFAULT_SETTINGS, $this->settings ?? []), $key, $default);
    }

    public function mergedSettings(): array
    {
        $merged = array_replace_recursive(self::DEFAULT_SETTINGS, $this->settings ?? []);
        $merged['photo_types'] ??= self::DEFAULT_PHOTO_TYPES;

        return $merged;
    }

    /** @return list<array{key: string, label: string}> */
    public function photoTypes(): array
    {
        return $this->mergedSettings()['photo_types'];
    }

    /** What a photo can be filed under. The original sections stay valid for installed apps that have them built in. */
    public function acceptedPhotoTypes(): array
    {
        return array_values(array_unique([...array_column($this->photoTypes(), 'key'), ...array_column(self::DEFAULT_PHOTO_TYPES, 'key')]));
    }

    /** Feature flag granted by the tenant's subscription plan. */
    public function feature(string $feature): bool
    {
        return (bool) (($this->plan?->features ?? [])[$feature] ?? false);
    }

    /** A channel is active only if the plan allows it AND the tenant switched it on. */
    public function channelEnabled(string $channel): bool
    {
        if ($channel === 'in_app') {
            return true;
        }
        if ($channel === 'push') {
            return (bool) $this->setting('notifications.push', true);
        }
        // Email goes out through the company's own SMTP account, so it isn't a plan add-on.
        if ($channel === 'email') {
            return (bool) $this->setting('notifications.email', false);
        }

        return $this->feature($channel) && (bool) $this->setting("notifications.$channel", false);
    }

    public function onlinePaymentsEnabled(): bool
    {
        return $this->feature('online_payments') && (bool) $this->setting('online_payments', false);
    }

    public function portalEnabled(): bool
    {
        return $this->feature('customer_portal') && (bool) $this->setting('customer_portal', true);
    }

    public function isUsable(): bool
    {
        if ($this->status === 'trial') {
            return ! $this->trial_ends_at || $this->trial_ends_at->isFuture();
        }

        return $this->status === 'active';
    }
}
