<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment recording. Offline collection by the service agent (cash / UPI / cheque /
 * bank transfer) is the primary flow; online gateway payment is optional.
 */
class PaymentService
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private NotificationService $notifications,
    ) {}

    public function recordOffline(Invoice $invoice, int $amount, string $method, array $meta = []): Payment
    {
        if (! in_array($method, PaymentMethod::offline(), true)) {
            throw ValidationException::withMessages(['method' => 'Invalid offline payment method.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }
        if (in_array($method, ['cheque', 'upi', 'bank_transfer'], true) && empty($meta['reference_no'])) {
            $label = ['cheque' => 'Cheque number', 'upi' => 'UPI transaction reference', 'bank_transfer' => 'Bank reference / UTR'][$method];
            throw ValidationException::withMessages(['reference_no' => "$label is required."]);
        }

        $payment = DB::transaction(function () use ($invoice, $amount, $method, $meta) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($amount > $invoice->balance_amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount exceeds the balance due ('.Money::format($invoice->balance_amount, $this->currency($invoice)).').',
                ]);
            }

            $tenant = Tenant::findOrFail($invoice->tenant_id);
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'job_visit_id' => $meta['job_visit_id'] ?? null,
                'amount' => $amount,
                'method' => $method,
                'receipt_number' => Sequence::formatted($invoice->tenant_id, 'receipt', $tenant->setting('receipt_prefix', 'RCPT')),
                'reference_no' => $meta['reference_no'] ?? null,
                'status' => 'success',
                'collected_by' => $meta['collected_by'] ?? auth()->id(),
                'remarks' => $meta['remarks'] ?? null,
                'paid_at' => $meta['paid_at'] ?? now(),
            ]);
            $invoice->refreshPaymentTotals();

            return $payment;
        });

        $this->notifyReceived($invoice->fresh(), $payment);

        return $payment;
    }

    /** Start a hosted-checkout payment for the invoice balance (FR-10.4). */
    public function createGatewayOrder(Invoice $invoice): array
    {
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        if (! $tenant->onlinePaymentsEnabled()) {
            throw ValidationException::withMessages(['invoice' => 'Online payment is not enabled for this company. Please pay the service agent or at the office.']);
        }
        if ($invoice->balance_amount <= 0) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already paid.']);
        }
        $gateway = $this->gateways->forTenant($tenant);
        if (! $gateway) {
            throw ValidationException::withMessages(['invoice' => 'Online payment is temporarily unavailable.']);
        }

        $order = $gateway->createOrder($invoice, $invoice->balance_amount, $tenant->currency);
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->balance_amount,
            'method' => PaymentMethod::Online->value,
            'gateway' => $gateway->name(),
            'gateway_order_id' => $order['order_id'],
            'status' => 'pending',
        ]);

        return $order + ['invoice_number' => $invoice->invoice_number, 'company' => $tenant->name];
    }

    /** Browser callback after checkout – signature must verify before we trust it. */
    public function confirmCheckout(Invoice $invoice, array $payload): Payment
    {
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        $gateway = $this->gateways->forTenant($tenant);
        if (! $gateway || ! $gateway->verifyCheckout($payload)) {
            throw ValidationException::withMessages(['payment' => 'Payment verification failed.']);
        }
        $payment = Payment::where('invoice_id', $invoice->id)
            ->where('gateway_order_id', $payload['razorpay_order_id'] ?? '')
            ->firstOrFail();

        return $this->markGatewayPayment($payment, 'success', $payload['razorpay_payment_id'] ?? null);
    }

    /** Server-to-server webhook; idempotent. */
    public function handleWebhook(string $driver, ?Tenant $tenant, string $rawBody, ?string $signature): bool
    {
        $gateway = $tenant ? $this->gateways->forTenant($tenant) : $this->gateways->forTenant(new Tenant);
        if (! $gateway || $gateway->name() !== $driver || ! $gateway->verifyWebhook($rawBody, $signature)) {
            return false;
        }
        $event = $gateway->parseWebhook(json_decode($rawBody, true) ?: []);
        if (! $event || ! $event['order_id']) {
            return true; // valid but irrelevant event
        }

        $context = app(TenantContext::class);
        $payment = $context->withoutScope(fn () => Payment::where('gateway_order_id', $event['order_id'])->first());
        if (! $payment || ($tenant && $payment->tenant_id !== $tenant->id)) {
            return true;
        }

        $context->runAs($payment->tenant_id, fn () => $this->markGatewayPayment($payment, $event['status'], $event['txn_id']));

        return true;
    }

    private function markGatewayPayment(Payment $payment, string $status, ?string $txnId): Payment
    {
        $changed = DB::transaction(function () use ($payment, $status, $txnId) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'success') {
                return false; // already processed
            }
            $tenant = Tenant::findOrFail($payment->tenant_id);
            $payment->update([
                'status' => $status,
                'gateway_txn_id' => $txnId,
                'paid_at' => $status === 'success' ? now() : null,
                'receipt_number' => $status === 'success'
                    ? Sequence::formatted($payment->tenant_id, 'receipt', $tenant->setting('receipt_prefix', 'RCPT'))
                    : null,
            ]);
            Invoice::findOrFail($payment->invoice_id)->refreshPaymentTotals();

            return $status === 'success';
        });

        $payment->refresh();
        if ($changed) {
            $this->notifyReceived(Invoice::findOrFail($payment->invoice_id), $payment);
        }

        return $payment;
    }

    private function notifyReceived(Invoice $invoice, Payment $payment): void
    {
        $invoice->loadMissing('job');
        $this->notifications->notifyCustomer($invoice->job, 'payment_received', [
            'amount' => Money::format($payment->amount, $this->currency($invoice)),
            'invoice_number' => $invoice->invoice_number,
            'receipt_number' => $payment->receipt_number,
        ]);
    }

    private function currency(Invoice $invoice): string
    {
        return Tenant::whereKey($invoice->tenant_id)->value('currency') ?? 'INR';
    }
}
