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

    public function test_counter_bill_with_gst_exclusive_prices_adds_gst_on_top(): void
    {
        $this->registerForGst();
        // ₹1,180 at 18% with ₹100 off: taxable 1,080, GST 194.40 (97.20 + 97.20), total 1,274.40.
        $bill = $this->walkIn(['discount' => 10000, 'prices_include_gst' => false, 'payment_method' => 'cash', 'amount_paid' => 50000]);

        $this->assertFalse($bill['prices_include_gst']);
        $this->assertSame(108000, $bill['taxable_amount']);
        $this->assertSame(9720, $bill['cgst_amount']);
        $this->assertSame(9720, $bill['sgst_amount']);
        $this->assertSame(127440, $bill['total_amount']);
        $this->assertSame(77440, $bill['balance_amount']);
        $this->assertSame('partial', $bill['payment_status']);

        foreach (['classic', 'modern'] as $design) {
            $this->putJson('/api/v1/settings/preferences', ['invoice_template' => $design])->assertOk();
            $this->get("/api/v1/invoices/{$bill['id']}/pdf")->assertOk();
        }
    }

    public function test_counter_bills_follow_the_gst_style_set_in_settings(): void
    {
        $this->registerForGst();
        // Out of the box prices include GST, as before.
        $inclusive = $this->walkIn();
        $this->assertTrue($inclusive['prices_include_gst']);
        $this->assertSame(118000, $inclusive['total_amount']);

        $this->putJson('/api/v1/settings/preferences', ['gst' => ['walkin_prices' => 'before_gst']])->assertOk()
            ->assertJsonPath('data.gst.walkin_prices', 'before_gst');
        $exclusive = $this->walkIn();
        $this->assertFalse($exclusive['prices_include_gst']);
        $this->assertSame(139240, $exclusive['total_amount']); // 1,180 + 18%

        // The person billing can still choose, and a bad value is refused.
        $this->assertTrue($this->walkIn(['prices_include_gst' => true])['prices_include_gst']);
        $this->putJson('/api/v1/settings/preferences', ['gst' => ['walkin_prices' => 'sometimes']])->assertStatus(422);
    }

    public function test_switching_an_exclusive_bill_to_non_gst_and_back_moves_the_total(): void
    {
        $this->registerForGst();
        $bill = $this->walkIn(['prices_include_gst' => false, 'payment_method' => 'cash', 'amount_paid' => 118000]);
        $this->assertSame(139240, $bill['total_amount']);

        // No GST: the customer pays the typed price, so the ₹1,180 already paid settles it.
        $this->postJson("/api/v1/invoices/{$bill['id']}/bill-type", ['is_gst' => false])->assertOk()
            ->assertJsonPath('data.total_amount', 118000)->assertJsonPath('data.balance_amount', 0)->assertJsonPath('data.payment_status', 'paid');

        $this->postJson("/api/v1/invoices/{$bill['id']}/bill-type", ['is_gst' => true])->assertOk()
            ->assertJsonPath('data.total_amount', 139240)->assertJsonPath('data.balance_amount', 21240)->assertJsonPath('data.payment_status', 'partial');
    }

    public function test_invoices_can_start_from_a_chosen_number_without_padding(): void
    {
        $this->putJson('/api/v1/settings/preferences', [
            'invoice_prefix' => 'AJCCP',
            'numbering' => ['invoice' => ['date' => 'none', 'separator' => 'none', 'digits' => 1]],
            'next_numbers' => ['invoice' => 18],
        ])->assertOk();

        $this->assertSame('AJCCP18', $this->walkIn()['invoice_number']);
        $this->assertSame('AJCCP19', $this->walkIn()['invoice_number']);
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.next_numbers.invoice', 20);

        $this->putJson('/api/v1/settings/preferences', ['next_numbers' => ['invoice' => 0]])->assertStatus(422);
    }

    public function test_non_gst_bills_can_run_in_their_own_series(): void
    {
        $this->registerForGst();
        $format = ['date' => 'none', 'separator' => 'dash', 'digits' => 3];
        $this->putJson('/api/v1/settings/preferences', [
            'invoice_prefix' => 'TAX', 'non_gst_invoice_prefix' => 'BOS', 'non_gst_numbering' => true,
            'numbering' => ['invoice' => $format, 'non_gst_invoice' => $format],
        ])->assertOk();

        $this->assertSame('TAX-001', $this->walkIn()['invoice_number']);
        $plain = $this->walkIn(['is_gst' => false]);
        $this->assertSame('BOS-001', $plain['invoice_number']);

        // A job bill made as non-GST takes a non-GST number: no GST number is used up.
        $this->putJson('/api/v1/settings/preferences', ['gst' => ['job_bills' => 'non_gst']])->assertOk();
        $this->assertSame('BOS-002', $this->jobInvoice()->invoice_number);
        $this->assertSame('TAX-002', $this->walkIn()['invoice_number']);

        // Switching a bill to GST moves it into the GST series.
        $this->postJson("/api/v1/invoices/{$plain['id']}/bill-type", ['is_gst' => true])->assertOk()
            ->assertJsonPath('data.invoice_number', 'TAX-003');

        // The two series can't share letters, or their numbers would look alike.
        $this->putJson('/api/v1/settings/preferences', ['non_gst_invoice_prefix' => 'TAX'])
            ->assertStatus(422)->assertJsonValidationErrors('non_gst_invoice_prefix');
    }

    public function test_counter_bill_lines_can_carry_their_own_gst_rate(): void
    {
        $this->registerForGst();
        $lines = [
            ['type' => 'service', 'description' => 'Gas top-up', 'quantity' => 1, 'unit_price' => 100000, 'gst_rate' => 5],
            ['type' => 'service', 'description' => 'Check-up', 'quantity' => 1, 'unit_price' => 100000],
        ];
        $bill = $this->postJson('/api/v1/walk-in-bills', [
            'customer' => ['name' => 'Asha', 'phone' => '9811100055'], 'prices_include_gst' => false, 'items' => $lines,
        ])->assertCreated()->json('data');

        // ₹1,000 at its own 5% + ₹1,000 at the Settings rate of 18%.
        $this->assertSame(223000, $bill['total_amount']);
        $this->assertSame([5.0, 18.0], array_map('floatval', array_column($bill['gst_breakup']['lines'], 'gst_rate')));
        $this->assertEquals(5, $bill['items'][0]['gst_rate']);
        $this->assertNull($bill['items'][1]['gst_rate']);

        $lines[0]['gst_rate'] = 7;
        $this->postJson('/api/v1/walk-in-bills', ['customer' => ['name' => 'Asha', 'phone' => '9811100055'], 'items' => $lines])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.gst_rate');
    }

    public function test_job_bills_stay_gst_inclusive_whatever_the_counter_setting(): void
    {
        $this->registerForGst();
        $this->putJson('/api/v1/settings/preferences', ['gst' => ['walkin_prices' => 'before_gst']])->assertOk();

        $invoice = $this->jobInvoice();
        $this->assertTrue($invoice->prices_include_gst);
        $this->assertSame(118000, $invoice->total_amount);
        $this->assertSame(100000, $invoice->taxable_amount);
    }

    public function test_job_bills_follow_their_own_gst_style_setting_and_the_technicians_choice(): void
    {
        $this->registerForGst();
        $this->putJson('/api/v1/settings/preferences', ['gst' => ['job_prices' => 'before_gst']])->assertOk();
        $tech = $this->punchIn($this->makeUser($this->tenant, Role::Technician));
        $customer = $this->makeCustomer($this->tenant, ['phone' => '9812300001']);
        $first = $this->makeJob($this->tenant, $customer, $tech);
        $second = $this->makeJob($this->tenant, $customer, $tech);
        $this->forgetGuards()->actingAsUser($tech);
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.billing.job_prices', 'before_gst');

        // GST on top (Settings): the app is told the exact amount to collect before the money is taken.
        $visit = $this->postJson("/api/v1/jobs/{$first->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/bill-preview", ['labour_charge' => 100000])->assertOk()
            ->assertJsonPath('data.prices_include_gst', false)->assertJsonPath('data.visit_tax', 18000)
            ->assertJsonPath('data.total', 118000)->assertJsonPath('data.due', 118000);
        $this->postJson("/api/v1/visits/{$visit}/bill-preview", ['labour_charge' => 100000, 'prices_include_gst' => true])
            ->assertJsonPath('data.total', 100000)->assertJsonPath('data.visit_tax', 15254);
        $this->postJson("/api/v1/visits/{$visit}/bill-preview", ['labour_charge' => 100000, 'is_gst' => false])
            ->assertJsonPath('data.total', 100000)->assertJsonPath('data.tax', 0);

        $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'pending', 'service_summary' => 'Checked', 'labour_charge' => 100000, 'payment_method' => 'cash', 'amount_collected' => 118000,
        ])->assertOk();
        $bill = Invoice::withoutGlobalScopes()->where('job_id', $first->id)->firstOrFail();
        $this->assertFalse($bill->prices_include_gst);
        $this->assertSame([118000, 100000, 118000, 0], [$bill->total_amount, $bill->taxable_amount, $bill->paid_amount, $bill->balance_amount]);

        // The technician can switch a new bill back to GST inclusive.
        $visit = $this->postJson("/api/v1/jobs/{$second->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", [
            'status' => 'pending', 'service_summary' => 'Checked', 'labour_charge' => 100000, 'payment_method' => 'credit', 'prices_include_gst' => true,
        ])->assertOk();
        $bill = Invoice::withoutGlobalScopes()->where('job_id', $second->id)->firstOrFail();
        $this->assertTrue($bill->prices_include_gst);
        $this->assertSame([100000, 84746], [$bill->total_amount, $bill->taxable_amount]);

        // A later visit adds to the bill in the bill's own style, whatever is chosen then.
        $this->forgetGuards()->actingAsUser($this->admin);
        $this->postJson("/api/v1/jobs/{$first->id}/resume/approve")->assertOk();
        $this->forgetGuards()->actingAsUser($tech);
        $visit = $this->postJson("/api/v1/jobs/{$first->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/bill-preview", ['labour_charge' => 50000, 'prices_include_gst' => true])->assertOk()
            ->assertJsonPath('data.prices_include_gst', false)->assertJsonPath('data.visit_tax', 9000)
            ->assertJsonPath('data.total', 177000)->assertJsonPath('data.paid', 118000)->assertJsonPath('data.due', 59000);

        // Another technician's visit can't be previewed.
        $other = $this->punchIn($this->makeUser($this->tenant, Role::Technician));
        $this->forgetGuards()->actingAsUser($other);
        $this->postJson("/api/v1/visits/{$visit}/bill-preview", ['labour_charge' => 50000])->assertNotFound();
    }

    public function test_the_invoice_list_filters_by_gst_style(): void
    {
        $this->registerForGst();
        $exclusive = $this->walkIn(['is_gst' => true, 'prices_include_gst' => false])['id'];
        $inclusive = $this->walkIn(['is_gst' => true, 'prices_include_gst' => true])['id'];
        $plain = $this->walkIn(['is_gst' => false])['id'];
        $ids = fn (string $gst) => collect($this->getJson("/api/v1/invoices?gst={$gst}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$exclusive, $inclusive], $ids('gst'));
        $this->assertSame([$exclusive], $ids('exclusive'));
        $this->assertSame([$inclusive], $ids('inclusive'));
        $this->assertSame([$plain], $ids('non_gst'));
        $this->assertCount(3, $ids(''));
        // The totals above the list follow the filter.
        $this->getJson('/api/v1/invoices?gst=non_gst')->assertJsonPath('meta.totals.total', 118000);
    }
}
