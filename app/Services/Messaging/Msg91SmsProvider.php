<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;

class Msg91SmsProvider implements MessagingProviderInterface
{
    public function __construct(private array $config) {}

    public function send(string $to, string $message, array $meta = []): array
    {
        $response = Http::timeout(10)->withHeaders(['authkey' => $this->config['auth_key']])
            ->post('https://control.msg91.com/api/v5/flow', [
                'template_id' => $meta['template_id'] ?? $this->config['template_id'] ?? null,
                'short_url' => 0,
                'recipients' => [['mobiles' => ltrim($to, '+'), 'message' => $message]],
            ]);

        return ['ok' => $response->successful(), 'error' => $response->successful() ? null : $response->body()];
    }
}
