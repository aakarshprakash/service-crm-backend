<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/** Writes messages to the log (default for local / unconfigured environments). */
class LogProvider implements MessagingProviderInterface
{
    public function __construct(private string $channel) {}

    public function send(string $to, string $message, array $meta = []): array
    {
        Log::info("[{$this->channel}] to {$to}: {$message}", $meta);

        return ['ok' => true, 'error' => null];
    }
}
