<?php

namespace App\Models;

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
        'gstin', 'logo_path', 'settings', 'trial_ends_at',
    ];

    protected $hidden = ['gateway_credentials'];

    public const DEFAULT_SETTINGS = [
        'invoice_prefix' => 'INV',
        'job_prefix' => 'SC',
        'receipt_prefix' => 'RCPT',
        'notifications' => ['sms' => false, 'whatsapp' => false, 'push' => true, 'email' => false],
        'online_payments' => false,
        'strict_cash_close' => true,
        'customer_portal' => true,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'gateway_credentials' => 'encrypted:array',
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
        return array_replace_recursive(self::DEFAULT_SETTINGS, $this->settings ?? []);
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
