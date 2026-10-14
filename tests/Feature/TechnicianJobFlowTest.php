<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Technician flow before a visit: punch in, check and correct the details, then start.
 */
class TechnicianJobFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visit_cannot_start_until_the_technician_punches_in(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $job = $this->makeJob($tenant, $this->makeCustomer($tenant), $tech);

        $this->actingAsUser($tech);
        $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])
            ->assertStatus(422)->assertJsonValidationErrors('punch');

        $this->punchIn($tech);
        $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated();
    }

    public function test_the_punch_in_rule_can_be_switched_off(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tenant->update(['settings' => array_replace_recursive($tenant->mergedSettings(), ['jobs' => ['require_punch_in' => false]])]);
        $tech = $this->makeUser($tenant, Role::Technician);
        $job = $this->makeJob($tenant, $this->makeCustomer($tenant), $tech);

        $this->actingAsUser($tech)->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'office'])->assertCreated();
    }

    public function test_technician_corrects_details_before_starting(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $other = $this->makeUser($tenant, Role::Technician);
        $customer = $this->makeCustomer($tenant);
        $productId = $this->inTenant($tenant, fn () => $customer->products()->first()->id);
        $job = $this->makeJob($tenant, $customer, $tech, ['customer_product_id' => $productId]);

        $this->actingAsUser($tech)->patchJson("/api/v1/jobs/{$job->id}/details", [
            'address' => '12, MG Road', 'pincode' => '560001', 'serial_no' => 'NEW-SN-1', 'complaint_details' => 'Noise from outdoor unit',
        ])->assertOk();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'address' => '12, MG Road', 'pincode' => '560001']);
        $this->assertDatabaseHas('customer_products', ['id' => $productId, 'serial_no' => 'NEW-SN-1']);
        $this->assertDatabaseHas('service_jobs', ['id' => $job->id, 'complaint_details' => 'Noise from outdoor unit']);

        // Someone else's job, or a finished one, can't be changed this way.
        $this->forgetGuards()->actingAsUser($other)->patchJson("/api/v1/jobs/{$job->id}/details", ['address' => 'X'])->assertStatus(422);
        $done = $this->makeJob($tenant, $customer, $tech, ['status' => 'completed']);
        $this->forgetGuards()->actingAsUser($tech)->patchJson("/api/v1/jobs/{$done->id}/details", ['address' => 'X'])->assertStatus(422);
    }

    public function test_job_shows_previous_services_for_the_same_product(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $customer = $this->makeCustomer($tenant);
        $productId = $this->inTenant($tenant, fn () => $customer->products()->first()->id);
        $old = $this->makeJob($tenant, $customer, $tech, ['customer_product_id' => $productId, 'status' => 'completed']);
        $this->inTenant($tenant, fn () => $old->visits()->create([
            'technician_id' => $tech->id, 'visit_date' => now()->subMonth()->toDateString(), 'service_type' => 'on_site',
            'start_time' => now()->subMonth(), 'end_time' => now()->subMonth(), 'status' => 'completed', 'service_summary' => 'Gas top-up done',
        ]));
        $job = $this->makeJob($tenant, $customer, $tech, ['customer_product_id' => $productId]);

        $this->actingAsUser($tech)->getJson("/api/v1/jobs/{$job->id}")->assertOk()
            ->assertJsonCount(1, 'data.previous_services')
            ->assertJsonPath('data.previous_services.0.summary', 'Gas top-up done')
            ->assertJsonPath('data.previous_services.0.crm_call_id', $old->crm_call_id);
    }

    public function test_dashboard_explains_the_punch_rules_to_the_technician(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $this->inTenant($tenant, fn () => Branch::whereKey($tech->branch_id)->update(['lat' => 12.97, 'lng' => 77.59, 'geofence_radius' => 200]));

        // Geofence on, but by default technicians may punch from anywhere.
        $tenant->update(['settings' => array_replace_recursive($tenant->mergedSettings(), ['attendance' => ['geofence' => 'enforce']])]);
        $this->actingAsUser($tech->fresh())->getJson('/api/v1/dashboard/technician')->assertOk()
            ->assertJsonPath('data.attendance.geofence', 'off')
            ->assertJsonPath('data.attendance.require_punch_in', true)
            ->assertJsonPath('data.attendance.branch.radius', 200);

        $tenant->update(['settings' => array_replace_recursive($tenant->mergedSettings(), ['attendance' => ['geofence_technicians' => true]])]);
        $this->getJson('/api/v1/dashboard/technician')->assertJsonPath('data.attendance.geofence', 'enforce');
    }
}
