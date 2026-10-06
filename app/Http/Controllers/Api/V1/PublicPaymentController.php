<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Unauthenticated payment-link endpoints (/pay/{token}) and gateway webhooks.
 * The token is a 48-char random secret; responses expose only what the payer needs.
 */
class PublicPaymentController extends Controller
{
    public function __construct(private PaymentService $payments, private InvoiceService $invoices) {}

    public function show(string $token): JsonResponse
    {
        [$invoice, $tenant] = $this->resolve($token);
        $invoice->loadMissing(['customer:id,name', 'job:id,crm_call_id']);

        return $this->ok([
            'company' => $tenant->name,
            'currency' => $tenant->currency,
            'invoice_number' => $invoice->invoice_number,
            'call_id' => $invoice->job?->crm_call_id,
            'customer' => $invoice->customer?->name,
            'generated_at' => $invoice->generated_at,
            'total_service_charge' => $invoice->total_service_charge,
            'total_spare_charge' => $invoice->total_spare_charge,
            'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'balance_amount' => $invoice->balance_amount,
            'payment_status' => $invoice->payment_status,
            'online_payments' => $tenant->onlinePaymentsEnabled(),
            'upi' => app(TenantContext::class)->runAs($invoice->tenant_id, fn () => $this->invoices->upi($invoice)),
        ]);
    }

    public function pdf(string $token): Response
    {
        [$invoice] = $this->resolve($token);

        return app(TenantContext::class)->runAs($invoice->tenant_id,
            fn () => $this->invoices->pdf($invoice)->download($invoice->invoice_number.'.pdf'));
    }

    public function createOrder(string $token): JsonResponse
    {
        [$invoice] = $this->resolve($token);

        return app(TenantContext::class)->runAs($invoice->tenant_id, fn () => $this->ok($this->payments->createGatewayOrder($invoice)));
    }

    public function confirm(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:100'],
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'max:200'],
        ]);
        [$invoice] = $this->resolve($token);
        $payment = app(TenantContext::class)->runAs($invoice->tenant_id, fn () => $this->payments->confirmCheckout($invoice, $data));

        return $this->ok(['status' => $payment->status, 'receipt_number' => $payment->receipt_number], 'Payment successful. Thank you!');
    }

    /** POST /payments/webhook/{driver}/{tenant?} – signature verified before anything is trusted. */
    public function webhook(Request $request, string $driver, ?string $tenant = null): JsonResponse
    {
        $tenantModel = $tenant ? Tenant::where('slug', $tenant)->first() : null;
        if ($tenant && ! $tenantModel) {
            return response()->json(['message' => 'Unknown account.'], 404);
        }

        $ok = $this->payments->handleWebhook($driver, $tenantModel, $request->getContent(), $request->header('X-Razorpay-Signature'));

        return $ok ? response()->json(['status' => 'ok']) : response()->json(['message' => 'Invalid signature.'], 401);
    }

    private function resolve(string $token): array
    {
        abort_unless(strlen($token) === 48 && ctype_alnum($token), 404);
        $invoice = app(TenantContext::class)->withoutScope(fn () => Invoice::where('pay_token', $token)->firstOrFail());
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        abort_unless($tenant->isUsable(), 404);
        // This request is bound to one invoice: scope everything else to its tenant.
        app(TenantContext::class)->set($invoice->tenant_id);

        return [$invoice, $tenant];
    }
}
