<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** The signed-in user's in-app notifications. */
    public function mine(Request $request): JsonResponse
    {
        $query = NotificationLog::where('user_id', $request->user()->id)->where('channel', 'in_app');
        $unread = (clone $query)->whereNull('read_at')->count();

        return $this->paginated($query->latest('id')->paginate($this->perPage($request, 20)), null, ['unread' => $unread]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:200'], 'ids.*' => ['integer']]);
        NotificationLog::where('user_id', $request->user()->id)->where('channel', 'in_app')->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return $this->ok(null, 'Marked as read.');
    }

    /** FR-12.3 delivery logs per tenant (SMS / WhatsApp / push). */
    public function logs(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $rows = NotificationLog::where('tenant_id', $tenantId)
            ->where('channel', '!=', 'in_app')
            ->where('type', '!=', 'otp')
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest('id')
            ->paginate($this->perPage($request));

        $stats = NotificationLog::where('tenant_id', $tenantId)->where('channel', '!=', 'in_app')->where('type', '!=', 'otp')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->paginated($rows, fn ($r) => array_merge($r->toArray(), [
            // Mask recipients (phone / device token) in the log view.
            'recipient' => $r->recipient ? substr($r->recipient, 0, 3).str_repeat('•', max(0, min(8, strlen($r->recipient) - 5))).substr($r->recipient, -2) : null,
        ]), ['stats' => $stats]);
    }
}
