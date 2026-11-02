<?php

namespace App\Services\Messaging;

use App\Models\Tenant;
use App\Services\TenantMailer;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Customer updates by email, through the company's own SMTP account or the platform mailer. */
class EmailProvider implements MessagingProviderInterface
{
    public function send(string $to, string $message, array $meta = []): array
    {
        $tenant = isset($meta['tenant_id']) ? Tenant::find($meta['tenant_id']) : null;
        $mailer = app(TenantMailer::class);
        $from = $mailer->sender($tenant);

        try {
            Mail::mailer($mailer->mailerFor($tenant))->raw(
                $message."\n\n— ".($tenant?->name ?? config('app.name')).($tenant?->phone ? "\n".$tenant->phone : ''),
                fn ($m) => $m->to($to)->from($from['from_address'], $from['from_name'])->subject($meta['title'] ?: 'Service update from '.($tenant?->name ?? config('app.name'))),
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }
}
