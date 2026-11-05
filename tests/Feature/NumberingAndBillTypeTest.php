<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Number formats from Settings, GST / non-GST bills, and the printed classic bill. */
class NumberingAndBillTypeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->actingAsUser($this->admin);
    }

    private function registerForGst(): void
    {
        $this->tenant->forceFill(['gstin' => '32BGKPA0180J1ZT', 'state' => 'Kerala'])->save();
    }

    private function newJobNumber(): string
    {
        return $this->postJson('/api/v1/jobs', ['customer_id' => $this->makeCustomer($this->tenant, ['phone' => '98'.random_int(10000000, 99999999)])->id, 'priority' => 'low', 'call_type' => 'crm_call'])
            ->assertCreated()->json('data.crm_call_id');
    }

    private function walkIn(array $extra = []): array
    {
        return $this->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Asha', 'phone' => '98'.random_int(10000000, 99999999)],
            'items' => [['type' => 'service', 'description' => 'Gas top-up', 'quantity' => 1, 'unit_price' => 118000]],
        ] + $extra)->assertCreated()->json('data');
    }

    private function jobInvoice(?array $settings = null): Invoice
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant, ['phone' => '98'.random_int(10000000, 99999999)]), $tech);
        $this->punchIn($tech);
        $this->forgetGuards();
        $this->actingAsUser($tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'pending', 'service_summary' => 'Checked', 'labour_charge' => 118000, 'payment_method' => 'credit'])->assertOk();
        $this->forgetGuards();
        $this->actingAsUser($this->admin);

        return Invoice::withoutGlobalScopes()->where('job_id', $job->id)->firstOrFail();
    }

    public function test_numbers_look_as_before_until_someone_changes_them(): void
    {
        $tz = $this->tenant->timezone;
        $this->assertMatchesRegularExpression('/^SC-'.now($tz)->format('ym').'-\d{5}$/', $this->newJobNumber());
        $this->assertMatchesRegularExpression('/^INV-'.now($tz)->format('ym').'-\d{5}$/', $this->walkIn()['invoice_number']);
    }

    public function test_the_client_format_with_letters_date_and_random_ending(): void
    {
        $this->putJson('/api/v1/settings/preferences', [
            'job_prefix' => 'AJ',
            'numbering' => ['job' => ['date' => 'yyyymmdd', 'ending' => 'random', 'digits' => 5, 'separator' => 'none']],
        ])->assertOk();

        $today = now($this->tenant->timezone)->format('Ymd');
        $numbers = collect(range(1, 3))->map(fn () => $this->newJobNumber());
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression("/^AJ{$today}\d{5}$/", $number);
        }
        $this->assertCount(3, $numbers->unique());
    }

    public function test_running_numbers_keep_counting_when_the_format_changes(): void
    {
        $first = $this->newJobNumber();
        $this->putJson('/api/v1/settings/preferences', ['numbering' => ['job' => ['date' => 'none', 'digits' => 3, 'separator' => 'dash']]])->assertOk();
        $second = $this->newJobNumber();

        $this->assertSame('SC-002', $second, "After {$first}");
        // Numbers already issued don't change.
        $this->assertTrue(ServiceJob::withoutGlobalScopes()->where('crm_call_id', $first)->exists());
    }

    public function test_a_number_already_in_use_is_skipped(): void
    {
        $this->putJson('/api/v1/settings/preferences', ['numbering' => ['job' => ['date' => 'none', 'digits' => 3]]])->assertOk();
        // Typed by hand as an external CRM call ID.
        $this->postJson('/api/v1/jobs', ['customer_id' => $this->makeCustomer($this->tenant)->id, 'priority' => 'low', 'call_type' => 'crm_call', 'crm_call_id' => 'SC-001'])->assertCreated();

        $this->assertSame('SC-002', $this->newJobNumber());
    }

    public function test_invoices_and_receipts_must_stay_consecutive(): void
    {
        $this->putJson('/api/v1/settings/preferences', ['numbering' => ['invoice' => ['ending' => 'random']]])->assertStatus(422)->assertJsonValidationErrors('numbering.invoice.ending');
        $this->putJson('/api/v1/settings/preferences', ['numbering' => ['receipt' => ['ending' => 'random']]])->assertStatus(422)->assertJsonValidationErrors('numbering.receipt.ending');
    }

    public function test_gst_invoice_numbers_are_limited_to_16_characters(): void
    {
        $tooLong = ['invoice_prefix' => 'AJCLIMATE', 'numbering' => ['invoice' => ['date' => 'yyyymmdd', 'digits' => 5, 'separator' => 'dash']]];

        // Without a GSTIN there's no such rule.
        $this->putJson('/api/v1/settings/preferences', $tooLong)->assertOk();
        $this->putJson('/api/v1/settings/preferences', ['invoice_prefix' => 'INV', 'numbering' => ['invoice' => ['date' => 'ym', 'digits' => 5]]])->assertOk();

        $this->registerForGst();
        $this->putJson('/api/v1/settings/preferences', $tooLong)->assertStatus(422)->assertJsonValidationErrors('numbering.invoice');
        $this->assertSame(14, DocumentNumber::length('INV', ['date' => 'ym', 'digits' => 5, 'separator' => 'dash']));
        // Letters count as letters, even ones that would mean something in a date format.
        $this->assertSame(20, DocumentNumber::length('AJ', ['date' => 'yyyymmdd', 'digits' => 8, 'separator' => 'dash']));
        $this->assertSame(10, DocumentNumber::length('DAY', ['date' => 'none', 'digits' => 6, 'separator' => 'dash']));
        $this->assertSame(11, DocumentNumber::length('AJ', ['date' => 'yymmdd', 'digits' => 3, 'separator' => 'none']));
    }

    public function test_technician_bills_follow_the_setting_and_keep_their_type(): void
    {
        $this->registerForGst();
        $gst = $this->jobInvoice();
        $this->assertTrue($gst->is_gst);
        $this->assertGreaterThan(0, $gst->cgst_amount);

        $this->putJson('/api/v1/settings/preferences', ['gst' => ['job_bills' => 'non_gst']])->assertOk();
        $plain = $this->jobInvoice();
        $this->assertFalse($plain->is_gst);
        $this->assertSame(0, $plain->cgst_amount);
        $this->assertSame(118000, $plain->total_amount);
        $this->assertSame(118000, $plain->taxable_amount);

        // A bill already issued as GST stays GST.
        $this->assertTrue($gst->fresh()->is_gst);
    }

    public function test_a_bill_can_be_switched_and_the_total_never_changes(): void
    {
        $this->registerForGst();
        $this->putJson('/api/v1/settings/preferences', ['gst' => ['job_bills' => 'non_gst']])->assertOk();
        $invoice = $this->jobInvoice();

        $this->postJson("/api/v1/invoices/{$invoice->id}/bill-type", ['is_gst' => true])->assertOk()
            ->assertJsonPath('data.is_gst', true)->assertJsonPath('data.total_amount', 118000)
            ->assertJsonPath('data.taxable_amount', 100000)->assertJsonPath('data.cgst_amount', 9000)->assertJsonPath('data.sgst_amount', 9000);

        $this->postJson("/api/v1/invoices/{$invoice->id}/bill-type", ['is_gst' => false])->assertOk()
            ->assertJsonPath('data.is_gst', false)->assertJsonPath('data.total_amount', 118000)->assertJsonPath('data.cgst_amount', 0)->assertJsonPath('data.gst_breakup', null);
    }

    public function test_a_switched_bill_keeps_its_type_after_another_visit(): void
    {
        $this->registerForGst();
        $invoice = $this->jobInvoice();
        $this->postJson("/api/v1/invoices/{$invoice->id}/bill-type", ['is_gst' => false])->assertOk();

        $tech = User::find(ServiceJob::withoutGlobalScopes()->find($invoice->job_id)->assigned_technician_id);
        $this->forgetGuards();
        $this->actingAsUser($tech);
        $this->allowResume($invoice->job_id);
        $visit = $this->postJson("/api/v1/jobs/{$invoice->job_id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'pending', 'service_summary' => 'Again', 'labour_charge' => 59000, 'payment_method' => 'credit'])->assertOk();

        $fresh = $invoice->fresh();
        $this->assertFalse($fresh->is_gst);
        $this->assertSame(177000, $fresh->total_amount);
    }

    public function test_walk_in_bills_can_be_chosen_per_bill(): void
    {
        // No GSTIN: plain bills only.
        $this->assertFalse($this->walkIn()['is_gst']);
        $this->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Asha', 'phone' => '9811100077'], 'is_gst' => true,
            'items' => [['type' => 'service', 'description' => 'Gas', 'quantity' => 1, 'unit_price' => 118000]],
        ])->assertStatus(422)->assertJsonValidationErrors('is_gst');

        $this->registerForGst();
        $this->assertTrue($this->walkIn()['is_gst']);
        $this->putJson('/api/v1/settings/preferences', ['gst' => ['walkin_bills' => 'non_gst']])->assertOk();
        $plain = $this->walkIn();
        $this->assertFalse($plain['is_gst']);
        $this->assertSame(118000, $plain['total_amount']);

        // The person billing can still choose either.
        $chosen = $this->walkIn(['is_gst' => true]);
        $this->assertTrue($chosen['is_gst']);
        $this->assertSame(118000, $chosen['total_amount']);
        $this->assertSame(100000, $chosen['taxable_amount']);
    }

    public function test_lookups_tell_the_billing_screen_what_to_offer(): void
    {
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.billing.gst_registered', false);
        $this->registerForGst();
        $this->putJson('/api/v1/settings/preferences', ['gst' => ['walkin_bills' => 'non_gst']])->assertOk();
        $this->forgetGuards();
        $this->actingAsUser($this->admin);
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.billing.gst_registered', true)->assertJsonPath('data.billing.walkin_bills', 'non_gst');
    }

    public function test_only_admins_and_accountants_change_a_bills_type(): void
    {
        $this->registerForGst();
        $invoice = $this->jobInvoice();

        foreach ([Role::Technician, Role::Coordinator, Role::ServiceTracker] as $role) {
            $this->forgetGuards();
            $this->actingAsUser($this->makeUser($this->tenant, $role));
            $this->postJson("/api/v1/invoices/{$invoice->id}/bill-type", ['is_gst' => false])->assertForbidden();
        }

        $this->forgetGuards();
        $this->actingAsUser($this->makeUser($this->tenant, Role::Accountant));
        $this->postJson("/api/v1/invoices/{$invoice->id}/bill-type", ['is_gst' => false])->assertOk();
    }

    public function test_bank_details_are_saved_and_the_bills_print_in_both_designs(): void
    {
        $this->registerForGst();
        $this->putJson('/api/v1/settings/preferences', ['bank' => ['account_name' => 'AJ CLIMATE CARE', 'bank_name' => 'HDFC', 'account_no' => '50200110461780', 'ifsc' => 'HDFC0000270', 'branch' => 'KANNUR']])->assertOk()
            ->assertJsonPath('data.bank.bank_name', 'HDFC');

        $gst = $this->walkIn();
        $plain = $this->walkIn(['is_gst' => false]);
        foreach ([$gst, $plain] as $bill) {
            foreach (['classic', 'modern'] as $design) {
                $this->putJson('/api/v1/settings/preferences', ['invoice_template' => $design])->assertOk();
                $response = $this->get("/api/v1/invoices/{$bill['id']}/pdf")->assertOk();
                $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
            }
        }
    }
}
