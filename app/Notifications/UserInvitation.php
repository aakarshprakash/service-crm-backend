<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Services\TenantMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $token, private string $companyName, private string $roleLabel) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
            'invite' => 1,
        ]);

        // Send through the company's own SMTP account when it has one, else the platform mailer.
        $tenant = Tenant::find($notifiable->tenant_id);
        $mailer = app(TenantMailer::class);
        $own = $tenant ? $mailer->mailerFor($tenant) : null;
        $from = $own ? $mailer->sender($tenant) : null;

        return (new MailMessage)
            ->mailer($own)
            ->when($from, fn (MailMessage $m) => $m->from($from['from_address'], $from['from_name']))
            ->subject("You're invited to {$this->companyName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have been added to {$this->companyName} as {$this->roleLabel}.")
            ->action('Set your password', $url)
            ->line('This link expires in 60 minutes. Ask your administrator to resend it if it expires.');
    }
}
