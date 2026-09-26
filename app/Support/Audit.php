<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function log(string $action, ?Model $model = null, array $changes = [], ?int $tenantId = null): void
    {
        $user = auth()->user();
        $request = app()->runningInConsole() ? null : request();

        AuditLog::create([
            'tenant_id' => $tenantId ?? $model?->tenant_id ?? app(TenantContext::class)->id(),
            'user_id' => $user?->id,
            'impersonator_id' => $request?->hasSession() ? $request->session()->get('impersonator_id') : null,
            'action' => $action,
            'model' => $model ? class_basename($model) : null,
            'model_id' => $model?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => $request?->ip(),
        ]);
    }
}
