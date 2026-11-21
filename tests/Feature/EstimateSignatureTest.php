<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The company signature and stamp: uploaded in Settings, printed on estimates by default, off by default on invoices. */
class EstimateSignatureTest extends TestCase
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

    public function test_signature_and_stamp_are_uploaded_served_and_removed(): void
    {
        Storage::fake('private');

        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.signature.has_signature', false)->assertJsonPath('data.signature.has_stamp', false);

        $this->postJson('/api/v1/settings/branding/signature', ['signature' => UploadedFile::fake()->image('sign.png', 600, 240)])->assertOk()->assertJsonPath('data.has_signature', true);
        $this->postJson('/api/v1/settings/branding/stamp', ['stamp' => UploadedFile::fake()->image('stamp.png', 400, 400)])->assertOk()->assertJsonPath('data.has_stamp', true);

        $this->getJson('/api/v1/settings')->assertJsonPath('data.has_signature', true)->assertJsonPath('data.has_stamp', true);
        $this->get('/api/v1/settings/branding/signature')->assertOk();
        $this->get('/api/v1/settings/branding/stamp')->assertOk();

        // An unknown kind is not a route.
        $this->postJson('/api/v1/settings/branding/logo', ['logo' => UploadedFile::fake()->image('x.png')])->assertNotFound();

        $this->deleteJson('/api/v1/settings/branding/signature')->assertOk()->assertJsonPath('data.has_signature', false);
        $this->get('/api/v1/settings/branding/signature')->assertNotFound();
    }

    public function test_the_default_follows_settings_and_each_estimate_can_override(): void
    {
        $customer = $this->makeCustomer($this->tenant);
        $line = ['items' => [['type' => 'service', 'description' => 'Labour', 'quantity' => 1, 'unit_price' => 100000]]];

        // Default on for estimates.
        $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id] + $line)->assertCreated()->assertJsonPath('data.show_signature', true);

        // Turned off in Settings → a new estimate starts without it; invoices default off from the start.
        $this->putJson('/api/v1/settings/preferences', ['signature' => ['estimates' => false, 'invoices' => true]])->assertOk();
        $this->getJson('/api/v1/settings')->assertJsonPath('data.settings.signature.estimates', false)->assertJsonPath('data.settings.signature.invoices', true);
        $id = $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id] + $line)->assertCreated()->assertJsonPath('data.show_signature', false)->json('data.id');

        // The estimate itself can turn it back on, and a copy keeps that choice.
        $this->patchJson("/api/v1/estimates/{$id}", ['show_signature' => true])->assertOk()->assertJsonPath('data.show_signature', true);
        $this->postJson("/api/v1/estimates/{$id}/duplicate")->assertCreated()->assertJsonPath('data.show_signature', true);
    }

    public function test_the_estimate_pdf_renders_with_the_signature_and_stamp(): void
    {
        Storage::fake('private');
        $this->postJson('/api/v1/settings/branding/signature', ['signature' => UploadedFile::fake()->image('sign.png', 600, 240)])->assertOk();
        $this->postJson('/api/v1/settings/branding/stamp', ['stamp' => UploadedFile::fake()->image('stamp.png', 400, 400)])->assertOk();

        $customer = $this->makeCustomer($this->tenant);
        $id = $this->postJson('/api/v1/estimates', ['customer_id' => $customer->id, 'show_signature' => true, 'items' => [['type' => 'service', 'description' => 'Labour', 'quantity' => 1, 'unit_price' => 100000]]])
            ->assertCreated()->json('data.id');

        $this->get("/api/v1/estimates/{$id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->patchJson("/api/v1/estimates/{$id}", ['show_signature' => false])->assertOk();
        $this->get("/api/v1/estimates/{$id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
