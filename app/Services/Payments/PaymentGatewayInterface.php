<?php

namespace App\Services\Payments;

use App\Models\Invoice;

/**
 * Hosted-checkout gateway abstraction (FR-10.4). No card data ever reaches the
 * backend; we only create orders and verify signed callbacks / webhooks.
 */
interface PaymentGatewayInterface
{
    public function name(): string;

    /** @return array{order_id:string, amount:int, currency:string, key:string, gateway:string} */
    public function createOrder(Invoice $invoice, int $amount, string $currency): array;

    /** Verify the signature returned to the browser after checkout. */
    public function verifyCheckout(array $payload): bool;

    public function verifyWebhook(string $rawBody, ?string $signature): bool;

    /** @return array{order_id:string, txn_id:?string, status:string, amount:int}|null */
    public function parseWebhook(array $payload): ?array;
}
