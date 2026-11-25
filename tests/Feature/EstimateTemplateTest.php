<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\EstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Estimate PDF designs: chosen in Settings, previewed there, and every design prints every kind of estimate. */
class EstimateTemplateTest extends TestCase
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

    private function estimate(array $extra = []): int
    {
        $customer = $this->makeCustomer($this->tenant, ['state' => 'Kerala']);

        return $this->postJson('/api/v1/estimates', $extra + [
            'customer_id' => $customer->id,
            'subject' => 'Supply and installation',
            'discount' => 10000,
            'items' => [
                ['type' => 'part', 'description' => 'Split AC 1.5T', 'details' => 'Copper coil, 5 star', 'unit' => 'Nos', 'quantity' => 2, 'unit_price' => 20000],
                ['type' => 'service', 'description' => 'Installation', 'quantity' => 1, 'unit_price' => 50000, 'gst_rate' => 5],
            ],
        ])->assertCreated()->json('data.id');
    }

    public function test_settings_list_the_estimate_designs_and_save_the_choice(): void
    {
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.estimate_templates', EstimateService::TEMPLATES)
            ->assertJsonPath('data.settings.estimate_template', 'modern');

        $this->putJson('/api/v1/settings/preferences', ['estimate_template' => 'classic'])->assertOk();
        $this->getJson('/api/v1/settings')->assertJsonPath('data.settings.estimate_template', 'classic');

        $this->putJson('/api/v1/settings/preferences', ['estimate_template' => 'fancy'])->assertStatus(422)->assertJsonValidationErrors('estimate_template');
    }

    public function test_the_chosen_design_decides_the_view_and_an_unknown_one_falls_back(): void
    {
        $service = app(EstimateService::class);
        $this->assertSame('pdf.estimate-modern', $service->viewFor($this->tenant));
        $this->assertSame('pdf.estimate-classic', $service->viewFor($this->tenant, 'classic'));

        $this->tenant->forceFill(['settings' => ['estimate_template' => 'classic'] + ($this->tenant->settings ?? [])])->save();
        $this->assertSame('pdf.estimate-classic', $service->viewFor($this->tenant->fresh()));

        // A design that no longer exists (an old setting) prints the standard one instead of failing.
        $this->tenant->forceFill(['settings' => ['estimate_template' => 'retired'] + ($this->tenant->settings ?? [])])->save();
        $this->assertSame('pdf.estimate-modern', $service->viewFor($this->tenant->fresh(), 'nope'));
    }

    public function test_every_design_prints_gst_added_gst_included_and_no_gst_estimates(): void
    {
        $ids = [
            'gst added' => $this->estimate(['is_gst' => true, 'prices_include_gst' => false]),
            'gst included' => $this->estimate(['is_gst' => true, 'prices_include_gst' => true]),
            'no gst' => $this->estimate(['is_gst' => false]),
            'other state' => $this->estimate(['is_gst' => true, 'customer_gstin' => '33AAACT1234A1Z8']),
        ];

        foreach (EstimateService::TEMPLATES as $template) {
            $this->putJson('/api/v1/settings/preferences', ['estimate_template' => $template])->assertOk();
            foreach ($ids as $kind => $id) {
                $response = $this->get("/api/v1/estimates/{$id}/pdf");
                $this->assertSame(200, $response->status(), "{$template} / {$kind}");
                $this->assertSame('application/pdf', $response->headers->get('content-type'), "{$template} / {$kind}");
            }
        }
    }

    public function test_a_design_can_be_previewed_with_the_latest_estimate(): void
    {
        $this->get('/api/v1/settings/estimate-preview?template=classic')->assertNotFound();

        $this->estimate();
        $this->get('/api/v1/settings/estimate-preview?template=classic')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson('/api/v1/settings/estimate-preview?template=fancy')->assertStatus(422);
    }
}
