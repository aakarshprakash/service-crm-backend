<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RazorpayGateway implements PaymentGatewayInterface
{
    public function __construct(
        private string $keyId,
        private string $keySecret,
        private ?string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function createOrder(Invoice $invoice, int $amount, string $currency): array
    {
        $response = Http::withBasicAuth($this->keyId, $this->keySecret)
            ->timeout(15)
            ->retry(2, 300, throw: false)
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amount,
                'currency' => $currency,
                'receipt' => $invoice->invoice_number,
                'notes' => ['tenant_id' => (string) $invoice->tenant_id, 'invoice_id' => (string) $invoice->id],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Payment gateway error: '.($response->json('error.description') ?? 'unavailable'));
        }

        return [
            'order_id' => $response->json('id'),
            'amount' => $amount,
            'currency' => $currency,
            'key' => $this->keyId,
            'gateway' => $this->name(),
        ];
    }

    public function verifyCheckout(array $payload): bool
    {
        $orderId = $payload['razorpay_order_id'] ?? '';
        $paymentId = $payload['razorpay_payment_id'] ?? '';
        $signature = $payload['razorpay_signature'] ?? '';
        if (! $orderId || ! $paymentId || ! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $this->keySecret), $signature);
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        if (! $this->webhookSecret || ! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $signature);
    }

    public function parseWebhook(array $payload): ?array
    {
        $event = $payload['event'] ?? '';
        $entity = data_get($payload, 'payload.payment.entity');
        if (! $entity || ! in_array($event, ['payment.captured', 'payment.failed', 'order.paid'], true)) {
            return null;
        }

        return [
            'order_id' => (string) ($entity['order_id'] ?? ''),
            'txn_id' => $entity['id'] ?? null,
            'status' => $event === 'payment.failed' ? 'failed' : 'success',
            'amount' => (int) ($entity['amount'] ?? 0),
        ];
    }
}
