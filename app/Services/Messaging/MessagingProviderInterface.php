<?php

namespace App\Services\Messaging;

interface MessagingProviderInterface
{
    /**
     * Send a message to a phone number / device token.
     *
     * @return array{ok:bool, error:?string}
     */
    public function send(string $to, string $message, array $meta = []): array;
}
