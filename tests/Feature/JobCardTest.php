<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Job card: brand-only units with the model added later, instant add of brands/models, rich-text details. */
class JobCardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    public function test_quick_add_brand_and_model_reuses_existing_names(): void
    {
        $tracker = $this->makeUser($this->tenant, Role::ServiceTracker)->fresh();
        $brand = $this->actingAsUser($tracker)->postJson('/api/v1/master/quick/brands', ['name' => 'Lloyd'])->assertCreated()->json('data');
        $again = $this->postJson('/api/v1/master/quick/brands', ['name' => ' LLOYD '])->assertCreated()->json('data');
        $this->assertSame($brand['id'], $again['id']);

        $model = $this->postJson('/api/v1/master/quick/products', ['model_name' => 'GLS18I5', 'brand_id' => $brand['id']])->assertCreated()->json('data');
        $this->assertSame('GLS18I5', $model['model_name']);
        $this->postJson('/api/v1/master/quick/dealers', ['name' => 'x'])->assertNotFound();

        // Technicians log no calls, so they can't add master data.
        $this->forgetGuards();
        $this->actingAsUser($this->makeUser($this->tenant, Role::Technician)->fresh())
            ->postJson('/api/v1/master/quick/brands', ['name' => 'Samsung'])->assertForbidden();
    }

    public function test_unit_with_only_brand_then_model_set_later(): void
    {
        $this->actingAsUser($this->admin);
        $brand = $this->postJson('/api/v1/master/quick/brands', ['name' => 'LLOYD'])->json('data');
        $type = $this->postJson('/api/v1/master/quick/categories', ['name' => 'INVERTER AC'])->json('data');
        $customer = $this->postJson('/api/v1/customers', [
            'name' => 'Asha', 'phone' => '9811100001',
            'products' => [['brand_id' => $brand['id'], 'category_id' => $type['id']]],
        ])->assertCreated()->json('data');
        $unit = $customer['products'][0];
        $this->assertNull($unit['product_id']);
        $this->assertSame($brand['id'], $unit['brand_id']);

        $job = $this->postJson('/api/v1/jobs', [
            'customer_id' => $customer['id'], 'customer_product_id' => $unit['id'], 'priority' => 'medium', 'call_type' => 'crm_call',
        ])->assertCreated()->json('data');
        $this->getJson("/api/v1/jobs/{$job['id']}")->assertOk()
            ->assertJsonPath('data.customer_product.brand.name', 'LLOYD')
            ->assertJsonPath('data.customer_product.category.name', 'INVERTER AC');

        // Model known later: brand and type follow the model.
        $other = $this->postJson('/api/v1/master/quick/brands', ['name' => 'SAMSUNG'])->json('data');
        $model = $this->postJson('/api/v1/master/quick/products', ['model_name' => 'AR18', 'brand_id' => $other['id'], 'category_id' => $type['id']])->json('data');
        $this->patchJson("/api/v1/customers/{$customer['id']}/products/{$unit['id']}", ['product_id' => $model['id']])->assertOk()
            ->assertJsonPath('data.brand_id', $other['id'])->assertJsonPath('data.product_id', $model['id']);
    }

    public function test_customers_with_an_email_get_job_updates_by_email_when_switched_on(): void
    {
        $this->actingAsUser($this->admin)->putJson('/api/v1/settings/preferences', ['notifications' => ['email' => true]])->assertOk();
        $withEmail = $this->postJson('/api/v1/customers', ['name' => 'Asha', 'phone' => '9811100001', 'email' => 'asha@example.com'])->json('data');
        $without = $this->postJson('/api/v1/customers', ['name' => 'Ravi', 'phone' => '9811100002'])->json('data');

        foreach ([$withEmail, $without] as $c) {
            $this->postJson('/api/v1/jobs', ['customer_id' => $c['id'], 'priority' => 'medium', 'call_type' => 'crm_call'])->assertCreated();
        }

        $emails = \App\Models\NotificationLog::withoutGlobalScopes()->where('channel', 'email')->get();
        $this->assertCount(1, $emails);
        $this->assertSame('asha@example.com', $emails[0]->recipient);
        $this->assertStringStartsWith('Service request', $emails[0]->title);
        $this->assertStringContainsString('Asha', $emails[0]->message);
    }

    public function test_job_card_prints_as_pdf_for_office_and_assigned_technician_only(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician)->fresh();
        $other = $this->makeUser($this->tenant, Role::Technician)->fresh();
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant), $tech, ['complaint_details' => '<p>Not <strong>cooling</strong></p>']);

        $this->actingAsUser($this->admin)->get("/api/v1/jobs/{$job->id}/job-card")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->forgetGuards();
        $this->actingAsUser($tech)->get("/api/v1/jobs/{$job->id}/job-card")->assertOk();
        $this->forgetGuards();
        $this->actingAsUser($other)->get("/api/v1/jobs/{$job->id}/job-card")->assertNotFound();
    }

    public function test_rich_text_details_are_sanitised_with_a_plain_text_copy(): void
    {
        $this->actingAsUser($this->admin);
        $customer = $this->makeCustomer($this->tenant);
        $job = $this->postJson('/api/v1/jobs', [
            'customer_id' => $customer->id, 'priority' => 'high', 'call_type' => 'crm_call',
            'complaint_details' => '<p>Not <strong>cooling</strong><script>alert(1)</script></p><ul><li>E4 error</li></ul><img src=x onerror=alert(1)>',
        ])->assertCreated()->json('data');

        $saved = $this->getJson("/api/v1/jobs/{$job['id']}")->json('data');
        $this->assertSame('<p>Not <strong>cooling</strong></p><ul><li>E4 error</li></ul>', $saved['complaint_details_html']);
        $this->assertSame("Not cooling\n• E4 error", $saved['complaint_details']);

        // Plain text (apps, portal) stays plain.
        $this->patchJson("/api/v1/jobs/{$job['id']}", ['complaint_details' => 'Gas low, 5 < 6 bar', 'priority' => 'high', 'call_type' => 'crm_call'])->assertOk();
        $saved = $this->getJson("/api/v1/jobs/{$job['id']}")->json('data');
        $this->assertNull($saved['complaint_details_html']);
        $this->assertSame('Gas low, 5 < 6 bar', $saved['complaint_details']);
    }
}
