<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Tenant $tenant, int $total = 100000): Invoice
    {
        return $this->inTenant($tenant, function () use ($tenant, $total) {
            $job = $this->makeJob($tenant, $this->makeCustomer($tenant));
            $job->visits()->create(['technician_id' => $this->makeUser($tenant, Role::Technician)->id, 'visit_date' => now()->toDateString(),
                'service_type' => 'on_site', 'start_time' => now(), 'end_time' => now(), 'status' => 'completed', 'labour_charge' => $total, 'total_charge' => $total]);

            return app(InvoiceService::class)->syncForJob($job);
        });
    }

    public function test_office_records_offline_payments_with_validation(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $accountant = $this->makeUser($tenant, Role::Accountant);
        $invoice = $this->invoice($tenant);
        $this->actingAsUser($accountant);

        $this->postJson("/api/v1/invoices/{$invoice->id}/pay", ['amount' => 50000, 'method' => 'cheque'])
            ->assertStatus(422)->assertJsonValidationErrors('reference_no');
        $this->postJson("/api/v1/invoices/{$invoice->id}/pay", ['amount' => 500000, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/invoices/{$invoice->id}/pay", ['amount' => 40000, 'method' => 'cash'])->assertCreated();
        $this->getJson("/api/v1/invoices/{$invoice->id}")->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.balance_amount', 60000);
        $this->postJson("/api/v1/invoices/{$invoice->id}/pay", ['amount' => 60000, 'method' => 'bank_transfer', 'reference_no' => 'UTR99'])->assertCreated();
        $this->getJson("/api/v1/invoices/{$invoice->id}")->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_invoice_pdf_downloads(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $invoice = $this->invoice($tenant);

        $response = $this->actingAsUser($admin)->get("/api/v1/invoices/{$invoice->id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_public_pay_link_hides_internal_data_and_rejects_bad_tokens(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $invoice = $this->invoice($tenant);
        $token = Invoice::withoutGlobalScopes()->find($invoice->id)->pay_token;

        $this->getJson("/api/v1/pay/{$token}")->assertOk()
            ->assertJsonPath('data.balance_amount', 100000)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.pay_token');
        $this->getJson('/api/v1/pay/'.str_repeat('a', 48))->assertNotFound();
        $this->getJson('/api/v1/pay/short')->assertNotFound();
    }

    public function test_online_payment_disabled_by_default(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $invoice = $this->invoice($tenant);
        $token = Invoice::withoutGlobalScopes()->find($invoice->id)->pay_token;

        $this->postJson("/api/v1/pay/{$token}/order")->assertStatus(422);
    }

    public function test_gateway_order_and_signed_webhook_mark_payment_once(): void
    {
        config(['services.razorpay' => ['key_id' => 'rzp_test_x', 'key_secret' => 'secret', 'webhook_secret' => 'whsec']]);
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_ABC'], 200)]);
        ['tenant' => $tenant] = $this->makeTenant();
        $tenant->update(['settings' => array_merge($tenant->mergedSettings(), ['online_payments' => true])]);
        $invoice = $this->invoice($tenant);
        $token = Invoice::withoutGlobalScopes()->find($invoice->id)->pay_token;

        $this->postJson("/api/v1/pay/{$token}/order")->assertOk()->assertJsonPath('data.order_id', 'order_ABC');

        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_ABC', 'amount' => 100000]]]]);

        // Bad signature → rejected, nothing changes.
        $this->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => 'forged', 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(401);
        $this->assertSame('pending', Payment::withoutGlobalScopes()->where('gateway_order_id', 'order_ABC')->value('status'));

        $sig = hash_hmac('sha256', $payload, 'whsec');
        foreach ([1, 2] as $attempt) { // idempotent on retries
            $this->call('POST', '/api/v1/payments/webhook/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload)
                ->assertOk();
        }

        $invoice = Invoice::withoutGlobalScopes()->find($invoice->id);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(100000, $invoice->paid_amount);
    }

    public function test_checkout_confirmation_requires_valid_signature(): void
    {
        config(['services.razorpay' => ['key_id' => 'rzp_test_x', 'key_secret' => 'secret', 'webhook_secret' => 'whsec']]);
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_XYZ'], 200)]);
        ['tenant' => $tenant] = $this->makeTenant();
        $tenant->update(['settings' => array_merge($tenant->mergedSettings(), ['online_payments' => true])]);
        $token = Invoice::withoutGlobalScopes()->find($this->invoice($tenant)->id)->pay_token;
        $this->postJson("/api/v1/pay/{$token}/order")->assertOk();

        $this->postJson("/api/v1/pay/{$token}/confirm", ['razorpay_order_id' => 'order_XYZ', 'razorpay_payment_id' => 'pay_9', 'razorpay_signature' => 'bad'])
            ->assertStatus(422);
        $this->postJson("/api/v1/pay/{$token}/confirm", [
            'razorpay_order_id' => 'order_XYZ', 'razorpay_payment_id' => 'pay_9',
            'razorpay_signature' => hash_hmac('sha256', 'order_XYZ|pay_9', 'secret'),
        ])->assertOk()->assertJsonPath('data.status', 'success');
    }
}
