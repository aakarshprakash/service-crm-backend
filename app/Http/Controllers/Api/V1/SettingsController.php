<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationTemplate;
use App\Models\SubscriptionPlan;
use App\Services\NotificationService;
use App\Support\TenantContext;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant()->load('plan');
        $credentials = $tenant->gateway_credentials ?: [];

        return $this->ok([
            'company' => $tenant->only(['name', 'slug', 'email', 'phone', 'address', 'gstin', 'timezone', 'currency', 'status', 'trial_ends_at']),
            'has_logo' => (bool) $tenant->logo_path,
            'settings' => $tenant->mergedSettings(),
            'plan' => $tenant->plan,
            'features' => collect(SubscriptionPlan::FEATURES)->mapWithKeys(fn ($f) => [$f => $tenant->feature($f)]),
            'gateway' => [
                'driver' => $credentials['driver'] ?? 'razorpay',
                'own_account' => ! empty($credentials['key_id']),
                // Only a masked hint is ever returned; secrets are write-only.
                'key_hint' => ! empty($credentials['key_id']) ? substr($credentials['key_id'], 0, 8).'••••' : null,
                'platform_available' => (bool) config('services.razorpay.key_id'),
                'webhook_url' => url("/api/v1/payments/webhook/razorpay/{$tenant->slug}"),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Z]{15}$/'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::in(['INR', 'USD', 'EUR', 'GBP', 'AED'])],
        ]);
        $tenant->update($data);

        return $this->ok($tenant->only(array_keys($data)), 'Company profile saved.');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $data = $request->validate([
            'invoice_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'job_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'receipt_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'notifications' => ['sometimes', 'array'],
            'notifications.sms' => ['boolean'],
            'notifications.whatsapp' => ['boolean'],
            'notifications.push' => ['boolean'],
            'online_payments' => ['sometimes', 'boolean'],
            'strict_cash_close' => ['sometimes', 'boolean'],
            'customer_portal' => ['sometimes', 'boolean'],
            'tutorial_mode' => ['sometimes', 'boolean'],
            'auto_assign_max_jobs' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'auto_assign_on_duty_only' => ['sometimes', 'boolean'],
            'jobs' => ['sometimes', 'array'],
            'jobs.require_signature' => ['sometimes', 'boolean'],
            'attendance' => ['sometimes', 'array'],
            'attendance.geofence' => ['sometimes', Rule::in(['off', 'flag', 'enforce'])],
            'attendance.geofence_technicians' => ['sometimes', 'boolean'],
            'attendance.require_location' => ['sometimes', 'boolean'],
        ]);
        $tenant->update(['settings' => array_replace_recursive($tenant->mergedSettings(), $data)]);

        return $this->ok($tenant->mergedSettings(), 'Settings saved.');
    }

    /** Tenant's own payment-gateway merchant account (§5.14). */
    public function updateGateway(Request $request): JsonResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(['razorpay'])],
            'key_id' => ['nullable', 'string', 'max:100', 'required_with:key_secret'],
            'key_secret' => ['nullable', 'string', 'max:200', 'required_with:key_id'],
            'webhook_secret' => ['nullable', 'string', 'max:200'],
            'remove' => ['boolean'],
        ]);
        $tenant = app(TenantContext::class)->tenant();
        $tenant->gateway_credentials = ($data['remove'] ?? false) ? null : array_filter([
            'driver' => $data['driver'],
            'key_id' => $data['key_id'] ?? null,
            'key_secret' => $data['key_secret'] ?? null,
            'webhook_secret' => $data['webhook_secret'] ?? null,
        ]);
        $tenant->save();

        return $this->ok(null, ($data['remove'] ?? false) ? 'Gateway credentials removed.' : 'Gateway credentials saved.');
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=2000,max_height=2000']]);
        $tenant = app(TenantContext::class)->tenant();
        if ($tenant->logo_path) {
            Storage::disk('private')->delete($tenant->logo_path);
        }
        $tenant->update(['logo_path' => $request->file('logo')->store("tenants/{$tenant->id}/branding", 'private')]);

        return $this->ok(['has_logo' => true], 'Logo updated.');
    }

    public function logo(): StreamedResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        abort_unless($tenant->logo_path && Storage::disk('private')->exists($tenant->logo_path), 404);

        return Storage::disk('private')->response($tenant->logo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function templates(): JsonResponse
    {
        $saved = NotificationTemplate::all()->keyBy(fn ($t) => $t->event.'.'.$t->channel);
        $rows = [];
        foreach (NotificationService::EVENTS as $event) {
            foreach (['sms', 'whatsapp'] as $channel) {
                $t = $saved[$event.'.'.$channel] ?? null;
                $rows[] = [
                    'event' => $event,
                    'channel' => $channel,
                    'body' => $t?->body ?? NotificationService::DEFAULT_TEMPLATES[$event],
                    'is_active' => $t?->is_active ?? true,
                    'customized' => (bool) $t,
                ];
            }
        }

        return $this->ok($rows);
    }

    public function saveTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(NotificationService::EVENTS)],
            'channel' => ['required', Rule::in(['sms', 'whatsapp'])],
            'body' => ['required', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
        $template = NotificationTemplate::updateOrCreate(
            ['event' => $data['event'], 'channel' => $data['channel']],
            ['body' => $data['body'], 'is_active' => $data['is_active'] ?? true]
        );

        return $this->ok($template, 'Template saved.');
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $rows = AuditLog::where('tenant_id', app(TenantContext::class)->id())
            ->with('user:id,name')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('model'), fn ($q) => $q->where('model', $request->string('model')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $this->like($request->string('action'))))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows);
    }
}
