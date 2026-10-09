<?php

namespace App\Services;

use App\Jobs\SendNotification;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Event notifications (§5.12). Every message is written to notification_logs
 * first, then delivered by a queued job, so delivery status is visible per tenant.
 */
class NotificationService
{
    public const DEFAULT_TEMPLATES = [
        'job_created' => 'Dear {customer_name}, your service request {call_id} has been registered with {company}. We will contact you shortly.',
        'technician_assigned' => 'Dear {customer_name}, technician {technician_name} ({technician_phone}) has been assigned to your request {call_id}. Scheduled: {scheduled_at}.',
        'job_completed' => 'Dear {customer_name}, your service request {call_id} is completed. Invoice {invoice_number}: {amount}, balance {balance}. {pay_link}',
        'payment_link' => 'Dear {customer_name}, please pay {balance} for invoice {invoice_number} ({company}): {pay_link}',
        'payment_received' => 'Dear {customer_name}, we received {amount} for invoice {invoice_number}. Receipt {receipt_number}. Thank you - {company}.',
        'followup_reminder' => 'Dear {customer_name}, a follow-up service {call_id} is scheduled on {scheduled_at}. - {company}',
        'location_request' => 'Dear {customer_name}, please share your location for service request {call_id} so our technician can reach you: {location_link} - {company}',
        'otp' => '{otp} is your {company} verification code. It expires in 5 minutes. Do not share it with anyone.',
    ];

    public const EVENTS = ['job_created', 'technician_assigned', 'job_completed', 'payment_link', 'payment_received', 'followup_reminder', 'location_request'];

    /** Customer SMS / WhatsApp for a job event (FR-12.2). */
    public function notifyCustomer(ServiceJob $job, string $event, array $vars = []): void
    {
        $tenant = app(TenantContext::class)->tenant() ?? Tenant::find($job->tenant_id);
        $job->loadMissing(['customer', 'technician']);
        $customer = $job->customer;
        if (! $tenant || ! $customer?->phone) {
            return;
        }

        $vars = array_merge([
            'customer_name' => $customer->name,
            'call_id' => $job->crm_call_id,
            'company' => $tenant->name,
            'technician_name' => $job->technician?->name ?? '-',
            'technician_phone' => $job->technician?->phone ?? '-',
            'scheduled_at' => $job->scheduled_at?->timezone($tenant->timezone)->format('d M Y, h:i A') ?? 'to be confirmed',
        ], $vars);

        $this->sendToCustomer($tenant, $customer, $event, $vars, ['job_id' => $job->id]);
    }

    /** Customer message about an invoice: via its job, or directly for a walk-in bill (no job). */
    public function notifyInvoiceCustomer(Invoice $invoice, string $event, array $vars = []): void
    {
        $invoice->loadMissing(['job', 'customer']);
        if ($invoice->job) {
            $this->notifyCustomer($invoice->job, $event, $vars);

            return;
        }
        $tenant = app(TenantContext::class)->tenant() ?? Tenant::find($invoice->tenant_id);
        $customer = $invoice->customer;
        if (! $tenant || ! $customer?->phone) {
            return;
        }
        $vars = array_merge([
            'customer_name' => $customer->name,
            'call_id' => $invoice->invoice_number,
            'company' => $tenant->name,
            'technician_name' => '-',
            'technician_phone' => '-',
            'scheduled_at' => '-',
        ], $vars);

        $this->sendToCustomer($tenant, $customer, $event, $vars, ['invoice_id' => $invoice->id]);
    }

    private function sendToCustomer(Tenant $tenant, Customer $customer, string $event, array $vars, array $data): void
    {
        foreach (['sms', 'whatsapp'] as $channel) {
            if (! $tenant->channelEnabled($channel)) {
                continue;
            }
            $body = $this->render($tenant, $event, $channel, $vars);
            if ($body === null) {
                continue;
            }
            $this->queue($tenant->id, [
                'customer_id' => $customer->id,
                'type' => $event,
                'channel' => $channel,
                'recipient' => $customer->phone,
                'message' => $body,
                'data' => $data,
            ]);
        }
    }

    /** Staff in-app notification, plus FCM push for users with registered devices (FR-12.1). */
    public function notifyUser(User $user, string $type, string $title, string $message, array $data = []): void
    {
        $tenantId = $user->tenant_id;
        NotificationLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'type' => $type,
            'channel' => 'in_app',
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        if ($tenant && ! $tenant->channelEnabled('push')) {
            return;
        }
        foreach ($user->deviceTokens()->pluck('token') as $token) {
            $this->queue($tenantId, [
                'user_id' => $user->id,
                'type' => $type,
                'channel' => 'push',
                'recipient' => $token,
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);
        }
    }

    /** Notify every active user of the given roles in the current tenant. */
    public function notifyRoles(array $roles, string $type, string $title, string $message, array $data = []): void
    {
        User::inTenant()->whereIn('role', $roles)->where('status', 'active')->get()
            ->each(fn (User $u) => $this->notifyUser($u, $type, $title, $message, $data));
    }

    public function sendOtp(?Tenant $tenant, string $phone, string $code): void
    {
        $body = strtr(self::DEFAULT_TEMPLATES['otp'], ['{otp}' => $code, '{company}' => $tenant?->name ?? config('app.name')]);
        $this->queue($tenant?->id, [
            'type' => 'otp',
            'channel' => 'sms',
            'recipient' => $phone,
            'message' => $body,
        ]);
    }

    public function render(Tenant $tenant, string $event, string $channel, array $vars): ?string
    {
        $template = NotificationTemplate::withoutGlobalScopes()
            ->where(['tenant_id' => $tenant->id, 'event' => $event, 'channel' => $channel])
            ->first();

        if ($template && ! $template->is_active) {
            return null;
        }
        $body = $template?->body ?? self::DEFAULT_TEMPLATES[$event] ?? null;
        if ($body === null) {
            return null;
        }
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return trim(preg_replace('/\{[a-z_]+\}/', '', strtr($body, $replacements)));
    }

    private function queue(?int $tenantId, array $attributes): void
    {
        $log = NotificationLog::create($attributes + ['tenant_id' => $tenantId, 'status' => 'queued']);
        SendNotification::dispatch($log->id)->afterCommit();
    }
}
