<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationTemplate;
use App\Models\SubscriptionPlan;
use App\Services\NotificationService;
use App\Services\EstimateService;
use App\Services\InvoiceService;
use App\Services\TenantMailer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Rules\Gstin;
use App\Support\IndianStates;
use Illuminate\Http\Response;
use App\Support\DocumentNumber;
use App\Support\TenantContext;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant()->load('plan');
        $credentials = $tenant->gateway_credentials ?: [];

        return $this->ok([
            'company' => $tenant->only(['name', 'slug', 'email', 'phone', 'address', 'state', 'gstin', 'pan', 'timezone', 'currency', 'status', 'trial_ends_at']),
            'states' => array_values(IndianStates::STATES),
            'invoice_templates' => InvoiceService::TEMPLATES,
            'estimate_templates' => EstimateService::TEMPLATES,
            'has_logo' => (bool) $tenant->logo_path,
            'has_signature' => (bool) $tenant->signature_path,
            'has_stamp' => (bool) $tenant->stamp_path,
            'settings' => $tenant->mergedSettings(),
            // What each running counter gives next, shown and changed under Settings → Numbering.
            'next_numbers' => DocumentNumber::nextValues($tenant),
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
            'email' => $this->emailPayload($tenant),
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
            'gstin' => ['nullable', 'string', new Gstin($request->input('state'))],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'state' => ['nullable', Rule::in(array_values(IndianStates::STATES))],
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
            'non_gst_invoice_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'non_gst_numbering' => ['sometimes', 'boolean'],
            'numbering' => ['sometimes', 'array'],
            'numbering.*.date' => ['sometimes', Rule::in(array_keys(DocumentNumber::DATE_FORMATS))],
            // 1: no padding, so 18 prints as AJCCP18 and grows to AJCCP100.
            'numbering.*.digits' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'numbering.*.separator' => ['sometimes', Rule::in(['dash', 'none'])],
            'numbering.job.ending' => ['sometimes', Rule::in(['counter', 'random'])],
            'numbering.invoice.ending' => ['sometimes', Rule::in(['counter'])],
            'numbering.non_gst_invoice.ending' => ['sometimes', Rule::in(['counter'])],
            'numbering.receipt.ending' => ['sometimes', Rule::in(['counter'])],
            // Where each running counter goes on from, e.g. a company moving over from another system.
            'next_numbers' => ['sometimes', 'array'],
            'next_numbers.*' => ['integer', 'min:1', 'max:99999999'],
            'notifications' => ['sometimes', 'array'],
            'notifications.sms' => ['boolean'],
            'notifications.whatsapp' => ['boolean'],
            'notifications.push' => ['boolean'],
            'notifications.email' => ['boolean'],
            'online_payments' => ['sometimes', 'boolean'],
            'strict_cash_close' => ['sometimes', 'boolean'],
            'customer_portal' => ['sometimes', 'boolean'],
            'tutorial_mode' => ['sometimes', 'boolean'],
            'auto_assign_max_jobs' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'auto_assign_on_duty_only' => ['sometimes', 'boolean'],
            'jobs' => ['sometimes', 'array'],
            'jobs.require_signature' => ['sometimes', 'boolean'],
            'jobs.require_punch_in' => ['sometimes', 'boolean'],
            'jobs.age_warning' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'jobs.age_old' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'jobs.age_critical' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'jobs.resume_needs_approval' => ['sometimes', 'boolean'],
            'jobs.auto_close_visits_at' => ['nullable', 'date_format:H:i'],
            'jobs.tech_hide_closed_after_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'jobs.tech_keep_until_feedback' => ['sometimes', 'boolean'],
            'tech_app' => ['sometimes', 'array'],
            'tech_app.show_leave' => ['sometimes', 'boolean'],
            'attendance' => ['sometimes', 'array'],
            'attendance.geofence' => ['sometimes', Rule::in(['off', 'flag', 'enforce'])],
            'attendance.geofence_technicians' => ['sometimes', 'boolean'],
            'attendance.require_location' => ['sometimes', 'boolean'],
            'attendance.auto_punch_out_at' => ['nullable', 'date_format:H:i'],
            'gst' => ['sometimes', 'array'],
            'gst.service_sac' => ['sometimes', 'string', 'regex:/^[0-9]{4,8}$/'],
            'gst.service_rate' => ['sometimes', 'numeric', Rule::in([0, 5, 12, 18, 28])],
            'gst.part_rate' => ['sometimes', 'numeric', Rule::in([0, 5, 12, 18, 28])],
            'gst.job_bills' => ['sometimes', Rule::in(['gst', 'non_gst'])],
            'gst.walkin_bills' => ['sometimes', Rule::in(['gst', 'non_gst'])],
            'gst.estimates' => ['sometimes', Rule::in(['gst', 'non_gst'])],
            'gst.estimate_prices' => ['sometimes', Rule::in(['before_gst', 'include_gst'])],
            'gst.walkin_prices' => ['sometimes', Rule::in(['before_gst', 'include_gst'])],
            'gst.job_prices' => ['sometimes', Rule::in(['before_gst', 'include_gst'])],
            'bank' => ['sometimes', 'array'],
            'bank.account_name' => ['nullable', 'string', 'max:150'],
            'bank.bank_name' => ['nullable', 'string', 'max:100'],
            'bank.account_no' => ['nullable', 'string', 'max:40'],
            'bank.ifsc' => ['nullable', 'string', 'max:20'],
            'bank.branch' => ['nullable', 'string', 'max:100'],
            'invoice_template' => ['sometimes', Rule::in(InvoiceService::TEMPLATES)],
            'estimate_template' => ['sometimes', Rule::in(EstimateService::TEMPLATES)],
            'estimate_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,8}$/'],
            'estimates' => ['sometimes', 'array'],
            'estimates.valid_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'estimates.terms' => ['nullable', 'string', 'max:3000'],
            'signature' => ['sometimes', 'array'],
            'signature.estimates' => ['sometimes', 'boolean'],
            'signature.invoices' => ['sometimes', 'boolean'],
            'photo_types' => ['sometimes', 'array', 'min:1', 'max:20'],
            'photo_types.*.key' => ['nullable', 'string', 'max:20'],
            'photo_types.*.label' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
        ], [
            'photo_types.min' => 'Keep at least one photo section.',
            'photo_types.max' => 'You can have up to 20 photo sections.',
            'photo_types.*.label.required' => 'Give every photo section a name.',
            'photo_types.*.label.distinct' => 'Two photo sections have the same name.',
        ]);
        $merged = array_replace_recursive($tenant->mergedSettings(), $data);
        // A list is replaced whole: merging would bring back sections that were removed.
        $merged['photo_types'] = isset($data['photo_types']) ? $this->photoSections($data['photo_types'], [...$tenant->acceptedPhotoTypes(), ...array_keys($tenant->setting('photo_labels', []))]) : $tenant->photoTypes();
        $merged['photo_labels'] = array_merge($tenant->setting('photo_labels', []), array_column($tenant->photoTypes(), 'label', 'key'), array_column($merged['photo_types'], 'label', 'key'));
        if ($merged['jobs']['age_warning'] >= $merged['jobs']['age_old']) {
            throw ValidationException::withMessages(['jobs.age_old' => 'Orange must start later than amber.']);
        }
        if ($merged['jobs']['age_old'] >= $merged['jobs']['age_critical']) {
            throw ValidationException::withMessages(['jobs.age_critical' => 'Red must start later than orange.']);
        }
        $invoiceLength = DocumentNumber::length($merged['invoice_prefix'], $merged['numbering']['invoice']);
        if ($tenant->gstin && $invoiceLength > DocumentNumber::GST_INVOICE_MAX_LENGTH) {
            throw ValidationException::withMessages(['numbering.invoice' => "GST invoice numbers can be at most ".DocumentNumber::GST_INVOICE_MAX_LENGTH." characters. This format makes {$invoiceLength}."]);
        }
        if ($merged['non_gst_numbering'] && $merged['non_gst_invoice_prefix'] === $merged['invoice_prefix']) {
            throw ValidationException::withMessages(['non_gst_invoice_prefix' => 'Use different letters for non-GST bills, so they can’t be mistaken for GST invoices.']);
        }
        $next = array_intersect_key($data['next_numbers'] ?? [], array_flip(DocumentNumber::TYPES));
        unset($merged['next_numbers']);

        DB::transaction(function () use ($tenant, $merged, $next) {
            $tenant->update(['settings' => $merged]);
            $current = DocumentNumber::nextValues($tenant);
            foreach ($next as $type => $value) {
                // Only a number someone changed: an unchanged one must not wind back numbers issued meanwhile.
                if ((int) $value !== $current[$type]) {
                    DocumentNumber::setNext($tenant, $type, (int) $value);
                }
            }
        });

        return $this->ok($tenant->mergedSettings(), 'Settings saved.');
    }

    /**
     * Existing sections keep their key, so a rename leaves their photos in place. A new one gets a key from
     * its name, short enough for job_images.type and clear of the kinds the app files separately.
     */
    private function photoSections(array $rows, array $known): array
    {
        $used = ['signature', 'voice', 'receipt'];
        $sections = [];
        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            if (! in_array($key, $known, true) || in_array($key, $used, true)) {
                $base = mb_substr(Str::slug($row['label'], '_'), 0, 16) ?: 'photo';
                $key = $base;
                for ($n = 2; in_array($key, $used, true) || in_array($key, $known, true); $n++) {
                    $key = "{$base}_{$n}";
                }
            }
            $used[] = $key;
            $sections[] = ['key' => $key, 'label' => trim($row['label'])];
        }

        return $sections;
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

    /** Company's own SMTP account (or the platform mailer when none is saved). The password is never returned. */
    private function emailPayload($tenant): array
    {
        $c = $tenant->mail_credentials ?: [];
        $platform = ! in_array(config('mail.default'), ['log', 'array'], true);

        return [
            'own_account' => ! empty($c['host']),
            'host' => $c['host'] ?? '',
            'port' => $c['port'] ?? 465,
            'encryption' => $c['encryption'] ?? 'ssl',
            'username' => $c['username'] ?? '',
            'from_address' => $c['from_address'] ?? '',
            'from_name' => $c['from_name'] ?? '',
            'has_password' => ! empty($c['password']),
            'platform_available' => $platform,
            'platform_from' => $platform ? config('mail.from.address') : null,
        ];
    }

    public function updateEmail(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class)->tenant();
        $data = $request->validate([
            'remove' => ['sometimes', 'boolean'],
            'host' => ['required_unless:remove,true', 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required_unless:remove,true', 'nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required_unless:remove,true', 'nullable', Rule::in(['ssl', 'tls', 'none'])],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:200'],
            'from_address' => ['required_unless:remove,true', 'nullable', 'email', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:100'],
        ]);

        if ($data['remove'] ?? false) {
            $tenant->mail_credentials = null;
            $tenant->save();

            return $this->ok($this->emailPayload($tenant), 'Email settings removed. The platform mail account will be used.');
        }

        $existing = $tenant->mail_credentials ?: [];
        $keepPassword = ($existing['username'] ?? null) === ($data['username'] ?? null) ? ($existing['password'] ?? null) : null;
        $tenant->mail_credentials = array_filter([
            'host' => strtolower($data['host']),
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'] ?? null,
            // Write-only: a blank password keeps the saved one (unless the username changed).
            'password' => ($data['password'] ?? '') !== '' ? $data['password'] : $keepPassword,
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $tenant->save();

        return $this->ok($this->emailPayload($tenant), 'Email settings saved.');
    }

    /** Send a real test message with the saved settings so the admin can confirm delivery. */
    public function testEmail(Request $request, TenantMailer $mailer): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:190']]);
        $tenant = app(TenantContext::class)->tenant();

        try {
            $via = $mailer->sendTest($tenant, $data['to']);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The test email could not be sent: '.mb_substr(strtok($e->getMessage(), "\n"), 0, 300),
                'errors' => ['to' => ['Sending failed – check the host, port, encryption, username and password.']],
            ], 422);
        }

        return $this->ok(null, "Test email sent to {$data['to']} using {$via}.");
    }

    /** Latest invoice rendered in the chosen design, so the admin can compare before switching. */
    public function invoicePreview(Request $request, InvoiceService $invoices): Response
    {
        $template = $request->validate(['template' => ['required', Rule::in(InvoiceService::TEMPLATES)]])['template'];
        $invoice = Invoice::latest('id')->first();
        abort_unless($invoice, 404, 'Create an invoice first to preview the designs.');

        return $invoices->pdf($invoice, $template)->stream("preview-{$template}.pdf");
    }

    /** An estimate design shown with the latest estimate, before choosing it. */
    public function estimatePreview(Request $request, EstimateService $estimates): Response
    {
        $template = $request->validate(['template' => ['required', Rule::in(EstimateService::TEMPLATES)]])['template'];
        $estimate = Estimate::latest('id')->first();
        abort_unless($estimate, 404, 'Create an estimate first to preview the designs.');

        return $estimates->pdf($estimate, $template)->stream("estimate-preview-{$template}.pdf");
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

    /** The column each brand image is stored in. A transparent PNG is best for a signature or stamp. */
    private const BRAND_IMAGES = ['signature' => 'signature_path', 'stamp' => 'stamp_path'];

    /** Upload the company signature or stamp/seal image (private storage, like the logo). */
    public function uploadBrandImage(Request $request, string $kind): JsonResponse
    {
        abort_unless(isset(self::BRAND_IMAGES[$kind]), 404);
        $request->validate([$kind => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=2000,max_height=2000']]);
        $tenant = app(TenantContext::class)->tenant();
        $column = self::BRAND_IMAGES[$kind];
        if ($tenant->{$column}) {
            Storage::disk('private')->delete($tenant->{$column});
        }
        $tenant->update([$column => $request->file($kind)->store("tenants/{$tenant->id}/branding", 'private')]);

        return $this->ok(["has_{$kind}" => true], ucfirst($kind).' updated.');
    }

    public function deleteBrandImage(string $kind): JsonResponse
    {
        abort_unless(isset(self::BRAND_IMAGES[$kind]), 404);
        $tenant = app(TenantContext::class)->tenant();
        $column = self::BRAND_IMAGES[$kind];
        if ($tenant->{$column}) {
            Storage::disk('private')->delete($tenant->{$column});
            $tenant->update([$column => null]);
        }

        return $this->ok(["has_{$kind}" => false], ucfirst($kind).' removed.');
    }

    public function brandImage(string $kind): StreamedResponse
    {
        abort_unless(isset(self::BRAND_IMAGES[$kind]), 404);
        $tenant = app(TenantContext::class)->tenant();
        $path = $tenant->{self::BRAND_IMAGES[$kind]};
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
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
