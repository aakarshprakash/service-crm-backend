<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;

/** Meta WhatsApp Business Cloud API. */
class WhatsAppCloudProvider implements MessagingProviderInterface
{
    public function __construct(private array $config) {}

    public function send(string $to, string $message, array $meta = []): array
    {
        $response = Http::timeout(10)->withToken($this->config['token'])
            ->post("https://graph.facebook.com/v20.0/{$this->config['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'text',
                'text' => ['body' => $message, 'preview_url' => true],
            ]);

        return ['ok' => $response->successful(), 'error' => $response->successful() ? null : $response->json('error.message')];
    }
}
