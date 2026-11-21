<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\IndianStates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GST on estimates follows Settings → GST: how a new one starts, how rates are typed, and each line's rate. */
class EstimateGstDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** A real-format Kerala GSTIN with a correct check digit. */
    private const KERALA_GSTIN = '32BGKPA0180J1ZT';

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tenant->forceFill(['gstin' => self::KERALA_GSTIN, 'state' => 'Kerala'])->save();
        $this->actingAsUser($this->admin);
    }

    private function line(array $extra = []): array
    {
        return ['items' => [['type' => 'service', 'description' => 'Labour', 'quantity' => 1, 'unit_price' => 100000] + $extra]];
    }

    public function test_gst_added_on_top_matches_a_supply_quotation(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['state' => 'Kerala']);
        $spec = 'Floor-mounted vertical factory-assembled indoor unit complete with scroll compressors, DX cooling coil with copper tubes. '.str_repeat('Matching air-condenser unit with copper-tube aluminium-fin condenser coil. ', 6);

        // Settings default: GST estimates, rates typed before GST, 18% on parts.
        $e = $this->postJson('/api/v1/estimates', [
            'customer_id' => $customer->id,
            'items' => [['type' => 'part', 'description' => 'Supply of VOLTAS 11tr Package AC Unit', 'details' => $spec, 'unit' => 'Nos', 'quantity' => 3, 'unit_price' => 22883200]],
        ])->assertCreated()->json('data');

        // TOTAL 6,86,496.00 · ADD GST @18% 1,23,569.28 · TOTAL INCLUDING GST 8,10,065.28
        $this->assertTrue($e['is_gst']);
        $this->assertFalse($e['prices_include_gst']);
        $this->assertSame(68649600, $e['taxable_amount']);
        $this->assertSame(6178464, $e['cgst_amount']);
        $this->assertSame(6178464, $e['sgst_amount']);
        $this->assertSame(0, $e['igst_amount']);
        $this->assertSame(81006528, $e['total_amount']);
        $this->assertSame('Kerala', $e['place_of_supply']);
        $this->assertStringStartsWith('GST is charged extra', $e['terms']);
        $this->assertSame(trim($spec), $e['items'][0]['details']);
        $this->assertSame('Nos', $e['items'][0]['unit']);
        $this->get("/api/v1/estimates/{$e['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        // A discount comes off before GST is added.
        $this->patchJson("/api/v1/estimates/{$e['id']}", ['discount' => 649600])->assertOk()
            ->assertJsonPath('data.taxable_amount', 68000000)
            ->assertJsonPath('data.total_amount', 80240000);

        // The same price typed as GST-inclusive keeps the total and splits the tax out of it.
        $this->patchJson("/api/v1/estimates/{$e['id']}", ['prices_include_gst' => true, 'discount' => 0])->assertOk()
            ->assertJsonPath('data.total_amount', 68649600)
            ->assertJsonPath('data.taxable_amount', 58177627);
    }

    public function test_each_line_takes_its_rate_from_settings_unless_changed_and_a_copy_keeps_them(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['state' => 'Kerala']);
        $part = $this->makeItem($this->tenant, 2);
        $id = $this->postJson('/api/v1/estimates', [
            'customer_id' => $customer->id,
            'items' => [
                ['type' => 'service', 'description' => 'Installation', 'quantity' => 1, 'unit_price' => 100000], // Settings: 18%
                ['type' => 'part', 'item_id' => $part->id, 'details' => 'With fittings', 'quantity' => 1, 'unit_price' => 100000, 'gst_rate' => 5],
            ],
        ])->assertCreated()->assertJsonPath('data.cgst_amount', 11500)->assertJsonPath('data.total_amount', 223000)->json('data.id');

        $copy = $this->postJson("/api/v1/estimates/{$id}/duplicate")->assertCreated()->json('data');
        $this->assertSame(223000, $copy['total_amount']);
        $this->assertNull($copy['items'][0]['gst_rate']);
        $this->assertEquals(5, $copy['items'][1]['gst_rate']);
        $this->assertSame('With fittings', $copy['items'][1]['details']);
        $this->assertSame($part->unit_of_measure, $copy['items'][1]['unit']);

        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id] + $this->line(['gst_rate' => 15]))
            ->assertStatus(422)->assertJsonValidationErrors('items.0.gst_rate');
    }

    public function test_a_new_estimate_starts_the_way_settings_say(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['state' => 'Kerala']);

        $this->putJson('/api/v1/settings/preferences', ['gst' => ['estimates' => 'non_gst', 'estimate_prices' => 'include_gst']])->assertOk();
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.billing.estimates', 'non_gst')->assertJsonPath('data.billing.estimate_prices', 'include_gst');
        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id] + $this->line())->assertCreated()
            ->assertJsonPath('data.is_gst', false)->assertJsonPath('data.prices_include_gst', true)->assertJsonPath('data.total_amount', 100000);

        // Chosen as GST on the estimate, rates still typed the Settings way: GST inside the price.
        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id, 'is_gst' => true] + $this->line())->assertCreated()
            ->assertJsonPath('data.total_amount', 100000)->assertJsonPath('data.taxable_amount', 84746);

        $this->putJson('/api/v1/settings/preferences', ['gst' => ['estimate_prices' => 'sideways']])->assertStatus(422);
    }

    public function test_a_customer_gstin_from_another_state_makes_it_igst(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['state' => 'Kerala']);

        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id, 'customer_gstin' => '33AAACT1234A1Z8'] + $this->line())->assertCreated()
            ->assertJsonPath('data.customer_gstin', '33AAACT1234A1Z8')
            ->assertJsonPath('data.igst_amount', 18000)
            ->assertJsonPath('data.cgst_amount', 0)
            ->assertJsonPath('data.place_of_supply', 'Tamil Nadu');

        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id, 'customer_gstin' => '33AAACT1234A1Z5'] + $this->line())
            ->assertStatus(422)->assertJsonValidationErrors('customer_gstin');
    }

    public function test_a_placeholder_company_gstin_does_not_move_the_company_to_another_state(): void
    {
        // Saved before GSTINs were checked: a sample number starting 29 (Karnataka) on a Kerala company.
        $this->tenant->forceFill(['gstin' => '29ABCDE1234F1ZR', 'state' => 'Kerala'])->save();
        $customer = $this->makeCustomer($this->tenant, ['state' => 'Kerala']);

        $this->assertSame('32', IndianStates::resolve('29ABCDE1234F1ZR', 'Kerala'));
        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id] + $this->line())->assertCreated()
            ->assertJsonPath('data.igst_amount', 0)->assertJsonPath('data.cgst_amount', 9000)->assertJsonPath('data.place_of_supply', 'Kerala');

        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.billing.gstin_valid', false);
    }

    public function test_company_and_customer_gstins_must_be_real_and_match_the_state(): void
    {
        $company = ['name' => 'AJ Climate Care', 'state' => 'Kerala', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR'];

        $this->putJson('/api/v1/settings/company', $company + ['gstin' => '29ABCDE1234F1ZR'])->assertStatus(422)->assertJsonValidationErrors('gstin');
        $this->putJson('/api/v1/settings/company', $company + ['gstin' => '29ABCDE1234F1ZW'])->assertStatus(422)
            ->assertJsonValidationErrors(['gstin' => 'Kerala GSTINs start with 32']);
        $this->putJson('/api/v1/settings/company', $company + ['gstin' => self::KERALA_GSTIN])->assertOk();
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.billing.gstin_valid', true);

        $this->postJson('/api/v1/customers', ['name' => 'Bad', 'phone' => '9811100001', 'gstin' => '32ABCDE1234F1Z5'])->assertStatus(422)->assertJsonValidationErrors('gstin');
        $this->postJson('/api/v1/customers', ['name' => 'Good', 'phone' => '9811100002', 'gstin' => '32ABCDE1234F1Z9', 'state' => 'Kerala'])->assertCreated();
    }
}
