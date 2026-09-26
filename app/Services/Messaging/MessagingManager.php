<?php

namespace App\Services\Messaging;

/**
 * Picks the configured provider per channel (config/services.php → messaging).
 * Swapping providers per region needs only configuration, not code changes.
 */
class MessagingManager
{
    private array $resolved = [];

    public function channel(string $channel): MessagingProviderInterface
    {
        return $this->resolved[$channel] ??= $this->make($channel);
    }

    private function make(string $channel): MessagingProviderInterface
    {
        $driver = config("services.messaging.$channel", 'log');

        return match ("$channel:$driver") {
            'sms:msg91' => new Msg91SmsProvider(config('services.msg91')),
            'sms:twilio' => new TwilioSmsProvider(config('services.twilio')),
            'whatsapp:twilio' => new TwilioSmsProvider(config('services.twilio'), whatsapp: true),
            'whatsapp:meta' => new WhatsAppCloudProvider(config('services.whatsapp')),
            'push:fcm' => new FcmProvider(config('services.fcm')),
            default => new LogProvider($channel),
        };
    }
}
