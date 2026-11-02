<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

/**
 * Outgoing email for a company. A company can save its own SMTP account in Settings;
 * without one, mail goes through the platform mailer configured in .env.
 */
class TenantMailer
{
    /** Name of a runtime mailer for the company's own SMTP account, or null to use the platform default. */
    public function mailerFor(?Tenant $tenant): ?string
    {
        $c = $tenant?->mail_credentials;
        if (! $c || empty($c['host']) || empty($c['from_address'])) {
            return null;
        }
        $name = 'tenant_'.$tenant->id;
        config(["mail.mailers.$name" => $this->transport($c)]);
        Mail::purge($name); // credentials may have changed since this process built the mailer

        return $name;
    }

    /** @return array{from_address: string, from_name: string} */
    public function sender(?Tenant $tenant): array
    {
        $c = $tenant?->mail_credentials ?: [];

        return [
            'from_address' => $c['from_address'] ?? config('mail.from.address'),
            'from_name' => $c['from_name'] ?? ($tenant?->name ?: config('mail.from.name')),
        ];
    }

    /** Send a plain test message and return where it went. Throws the transport error on failure. */
    public function sendTest(?Tenant $tenant, string $to): string
    {
        $mailer = $this->mailerFor($tenant);
        $from = $this->sender($tenant);
        Mail::mailer($mailer)->raw(
            "This is a test email from ".($tenant?->name ?: config('app.name')).".\n\nIf you can read this, your email settings are working.",
            fn ($m) => $m->to($to)->from($from['from_address'], $from['from_name'])->subject('Test email – '.config('app.name'))
        );

        return $mailer ? 'your company SMTP account' : 'the platform mail account';
    }

    private function transport(array $c): array
    {
        $encryption = $c['encryption'] ?? 'ssl';

        return [
            'transport' => 'smtp',
            'host' => $c['host'],
            'port' => (int) ($c['port'] ?? 465),
            // smtps = implicit TLS (port 465); plain smtp upgrades via STARTTLS when offered (port 587).
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'username' => $c['username'] ?? null,
            'password' => $c['password'] ?? null,
            'timeout' => 20,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ];
    }
}
