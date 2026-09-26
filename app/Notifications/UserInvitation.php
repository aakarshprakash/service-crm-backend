<?php

namespace App\Notifications;

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

        return (new MailMessage)
            ->subject("You're invited to {$this->companyName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have been added to {$this->companyName} as {$this->roleLabel}.")
            ->action('Set your password', $url)
            ->line('This link expires in 60 minutes. Ask your administrator to resend it if it expires.');
    }
}
