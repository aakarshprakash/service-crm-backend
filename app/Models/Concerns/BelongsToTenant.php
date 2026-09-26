<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            if ($context->has()) {
                // Always the resolved tenant, never a client-supplied value.
                $model->tenant_id = $context->id();
            } elseif (! $model->tenant_id) {
                throw new LogicException('Cannot create '.class_basename($model).' without a tenant context.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
