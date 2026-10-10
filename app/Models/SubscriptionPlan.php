<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use Auditable;

    protected $fillable = ['name', 'code', 'price', 'billing_cycle', 'max_users', 'max_technicians', 'features', 'is_active'];

    /** manager_app: office staff may use the Servon Manager phone app (an add-on). */
    public const FEATURES = ['customer_portal', 'sms', 'whatsapp', 'online_payments', 'advanced_reports', 'manager_app'];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'plan_id');
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'price' => 'integer',
        ];
    }
}
