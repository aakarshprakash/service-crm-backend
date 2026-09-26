<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;

/** Twilio SMS, or Twilio as a WhatsApp BSP when $whatsapp is true. */
class TwilioSmsProvider implements MessagingProviderInterface
{
    public function __construct(private array $config, private bool $whatsapp = false) {}

    public function send(string $to, string $message, array $meta = []): array
    {
        $prefix = $this->whatsapp ? 'whatsapp:' : '';
        $response = Http::timeout(10)->asForm()
            ->withBasicAuth($this->config['sid'], $this->config['token'])
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->config['sid']}/Messages.json", [
                'From' => $prefix.$this->config['from'],
                'To' => $prefix.$to,
                'Body' => $message,
            ]);

        return ['ok' => $response->successful(), 'error' => $response->successful() ? null : $response->json('message')];
    }
}
