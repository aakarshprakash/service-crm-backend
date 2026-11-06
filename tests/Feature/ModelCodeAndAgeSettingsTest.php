<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Typed model with suggestions, the Customer Satisfaction Code, job-age bands in Settings, and the location trend. */
class ModelCodeAndAgeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    public function test_model_is_typed_text_and_earlier_entries_are_suggested(): void
    {
        $this->actingAsUser($this->admin);
        $brand = $this->postJson('/api/v1/master/quick/brands', ['name' => 'LLOYD'])->json('data');
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Asha', 'phone' => '9811100001', 'products' => [['brand_id' => $brand['id'], 'model_no' => 'GLS18I5']]])->assertCreated()->json('data');
        $unit = $customer['products'][0];
        $this->assertSame('GLS18I5', $unit['model_no']);
        $this->assertNull($unit['product_id']);

        // Typed models and their brand come back as suggestions, once each.
        $this->postJson("/api/v1/customers/{$customer['id']}/products", ['brand_id' => $brand['id'], 'model_no' => 'GLS18I5'])->assertCreated();
        $this->postJson("/api/v1/customers/{$customer['id']}/products", ['model_no' => 'TOWER-9'])->assertCreated();
        $suggestions = collect($this->getJson('/api/v1/master/lookups')->assertOk()->json('data.model_suggestions'));
        $this->assertCount(1, $suggestions->where('model_no', 'GLS18I5'));
        $this->assertSame($brand['id'], $suggestions->firstWhere('model_no', 'GLS18I5')['brand_id']);
        $this->assertNotNull($suggestions->firstWhere('model_no', 'TOWER-9'));

        // Searchable from the Jobs list.
        $job = $this->postJson('/api/v1/jobs', ['customer_id' => $customer['id'], 'customer_product_id' => $unit['id'], 'priority' => 'low', 'call_type' => 'crm_call'])->assertCreated()->json('data.id');
        $this->assertSame([$job], array_column($this->getJson('/api/v1/jobs?search=GLS18')->json('data'), 'id'));
        $this->assertSame('GLS18I5', $this->getJson('/api/v1/jobs')->json('data.0.customer_product.model_no'));
    }

    public function test_a_master_list_model_still_works_and_typing_replaces_it(): void
    {
        $this->actingAsUser($this->admin);
        $brand = $this->postJson('/api/v1/master/quick/brands', ['name' => 'LLOYD'])->json('data');
        $model = $this->postJson('/api/v1/master/quick/products', ['model_name' => 'OLD-1', 'brand_id' => $brand['id']])->json('data');
        // Technician apps already installed still send product_id.
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Asha', 'phone' => '9811100001', 'products' => [['product_id' => $model['id']]]])->assertCreated()->json('data');
        $unit = $customer['products'][0];
        $this->assertSame('OLD-1', $unit['model_no']);

        $updated = $this->patchJson("/api/v1/customers/{$customer['id']}/products/{$unit['id']}", ['model_no' => 'NEW-2'])->assertOk()->json('data');
        $this->assertSame('NEW-2', $updated['model_no']);
        $this->assertNull($updated['product_id']);
    }

    public function test_technician_enters_model_and_code_during_the_visit(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $this->actingAsUser($this->admin);
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Asha', 'phone' => '9811100001', 'products' => [['serial_no' => 'S1']]])->assertCreated()->json('data');
        $job = $this->postJson('/api/v1/jobs', [
            'customer_id' => $customer['id'], 'customer_product_id' => $customer['products'][0]['id'], 'priority' => 'low', 'call_type' => 'crm_call', 'assigned_technician_id' => $tech->id,
        ])->assertCreated()->json('data.id');

        $this->forgetGuards();
        $this->punchIn($tech);
        $this->actingAsUser($tech);

        // Before starting: "check details".
        $this->patchJson("/api/v1/jobs/{$job}/details", ['model_no' => 'M-1'])->assertOk();
        $visit = $this->postJson("/api/v1/jobs/{$job}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');

        // During the visit: the model can still be corrected.
        $this->patchJson("/api/v1/visits/{$visit}", ['model_no' => 'M-2', 'customer_satisfaction_code' => 'KKD-111'])->assertOk();
        $this->getJson("/api/v1/jobs/{$job}")->assertJsonPath('data.customer_satisfaction_code', 'KKD-111')->assertJsonPath('data.customer_product.model_no', 'M-2');

        // When closing: a new code replaces it, and leaving it blank keeps it.
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'pending', 'service_summary' => 'Checked', 'customer_satisfaction_code' => 'KKD-222'])->assertOk();
        $this->getJson("/api/v1/jobs/{$job}")->assertJsonPath('data.customer_satisfaction_code', 'KKD-222');
        $this->allowResume($job);
        $second = $this->postJson("/api/v1/jobs/{$job}/visits/start", ['service_type' => 'tele_call'])->json('data.id');
        $this->postJson("/api/v1/visits/{$second}/complete", ['status' => 'pending', 'service_summary' => 'Again', 'customer_satisfaction_code' => null])->assertOk();
        $this->getJson("/api/v1/jobs/{$job}")->assertJsonPath('data.customer_satisfaction_code', 'KKD-222');

        $this->patchJson("/api/v1/visits/{$second}", ['customer_satisfaction_code' => 'late'])->assertStatus(422);
    }

    public function test_office_can_edit_and_search_the_code(): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant));
        $this->actingAsUser($this->admin);
        $this->patchJson("/api/v1/jobs/{$job->id}", ['customer_satisfaction_code' => 'KKD-900'])->assertOk();
        $this->assertSame([$job->id], array_column($this->getJson('/api/v1/jobs?search=KKD-900')->json('data'), 'id'));
        $this->patchJson("/api/v1/jobs/{$job->id}", ['customer_satisfaction_code' => str_repeat('x', 51)])->assertStatus(422);
    }

    public function test_job_card_pdf_shows_typed_model_and_code(): void
    {
        $this->actingAsUser($this->admin);
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Asha', 'phone' => '9811100001', 'products' => [['model_no' => 'M-77']]])->assertCreated()->json('data');
        $job = $this->postJson('/api/v1/jobs', ['customer_id' => $customer['id'], 'customer_product_id' => $customer['products'][0]['id'], 'priority' => 'low', 'call_type' => 'crm_call', 'customer_satisfaction_code' => 'C-1'])->assertCreated()->json('data.id');
        $this->get("/api/v1/jobs/{$job}/job-card")->assertOk();
    }

    public function test_age_bands_come_from_settings_and_drive_old_jobs(): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant));
        $this->inTenant($this->tenant, fn () => ServiceJob::whereKey($job->id)->toBase()->update(['created_at' => now()->subDays(5)]));
        $this->actingAsUser($this->admin);

        // Defaults: 3 / 7 / 14, so a 5-day-old job isn't "old" yet.
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.job_age.warning', 3)->assertJsonPath('data.job_age.old', 7)->assertJsonPath('data.job_age.critical', 14);
        $this->getJson('/api/v1/jobs/quick-counts')->assertJsonPath('data.aged', 0);

        $this->putJson('/api/v1/settings/preferences', ['jobs' => ['age_warning' => 2, 'age_old' => 4, 'age_critical' => 10]])->assertOk();
        $this->forgetGuards();
        $this->actingAsUser($this->admin);
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.job_age.old', 4);
        $this->getJson('/api/v1/jobs/quick-counts')->assertJsonPath('data.aged', 1);
        $this->getJson('/api/v1/jobs/location-analysis')->assertJsonPath('data.aged_days', 4);

        // They must go up in order, and other settings are untouched.
        $this->putJson('/api/v1/settings/preferences', ['jobs' => ['age_old' => 1]])->assertStatus(422)->assertJsonValidationErrors('jobs.age_old');
        $this->putJson('/api/v1/settings/preferences', ['jobs' => ['age_critical' => 4]])->assertStatus(422)->assertJsonValidationErrors('jobs.age_critical');
        $this->getJson('/api/v1/master/lookups')->assertJsonPath('data.job_age.critical', 10);
    }

    public function test_location_trend_counts_days_and_switches_to_months(): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant));
        $this->inTenant($this->tenant, fn () => ServiceJob::whereKey($job->id)->toBase()->update(['status' => 'completed', 'created_at' => now()->subDays(2), 'completed_at' => now()->subDay()]));
        $this->actingAsUser($this->admin);

        $tz = $this->tenant->timezone;
        $data = $this->getJson('/api/v1/jobs/location-analysis?from='.now($tz)->subDays(4)->toDateString().'&to='.now($tz)->toDateString())->assertOk()->json('data.trend');
        $this->assertSame('day', $data['bucket']);
        $this->assertCount(5, $data['points']);
        $points = collect($data['points'])->keyBy('date');
        $this->assertSame(1, $points[now($tz)->subDays(2)->toDateString()]['logged']);
        $this->assertSame(1, $points[now($tz)->subDay()->toDateString()]['completed']);

        $long = $this->getJson('/api/v1/jobs/location-analysis?from='.now($tz)->subMonths(5)->toDateString().'&to='.now($tz)->toDateString())->assertOk()->json('data.trend');
        $this->assertSame('month', $long['bucket']);
        $this->assertCount(6, $long['points']);
        $this->assertSame(1, array_sum(array_column($long['points'], 'logged')));
    }

    public function test_adding_a_service_area_with_technicians_shows_on_the_analysis(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician, ['name' => 'Vipin']);
        $this->actingAsUser($this->admin);
        $id = $this->postJson('/api/v1/master/service-locations', ['name' => 'Payyanur', 'city' => 'Kannur', 'pincodes' => '670307', 'is_active' => true, 'technician_ids' => [$tech->id]])->assertCreated()->json('data.id');

        $row = $this->getJson('/api/v1/jobs/location-analysis')->assertOk()->json('data.locations.0');
        $this->assertSame($id, $row['id']);
        $this->assertSame('670307', $row['pincodes']);
        $this->assertSame(1, $row['linked_technicians']);
        $this->assertTrue($row['technicians'][0]['linked']);

        $this->patchJson("/api/v1/master/service-locations/{$id}", ['name' => 'Payyanur', 'technician_ids' => []])->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/jobs/location-analysis')->json('data.locations.0.linked_technicians'));

        $this->forgetGuards();
        $this->actingAsUser($this->makeUser($this->tenant, Role::Coordinator))->postJson('/api/v1/master/service-locations', ['name' => 'Nope'])->assertForbidden();
    }
}
