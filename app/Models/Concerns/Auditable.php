<?php

namespace App\Models\Concerns;

use App\Support\Audit;

/**
 * Writes create / update / delete events to audit_logs (who, what, when).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => Audit::log('created', $model, $model->auditAttributes($model->getAttributes())));
        static::updated(function ($model) {
            $changes = $model->auditAttributes($model->getChanges());
            unset($changes['updated_at']);
            if ($changes) {
                $old = array_intersect_key($model->getOriginal(), $changes);
                Audit::log('updated', $model, ['old' => $model->auditAttributes($old), 'new' => $changes]);
            }
        });
        static::deleted(fn ($model) => Audit::log('deleted', $model));
    }

    protected function auditAttributes(array $attributes): array
    {
        $hidden = array_merge($this->getHidden(), ['password', 'remember_token', 'two_factor_secret', 'gateway_credentials']);

        return array_diff_key($attributes, array_flip($hidden));
    }
}
