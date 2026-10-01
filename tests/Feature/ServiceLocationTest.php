<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ServiceLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceLocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    private function location(string $name, array $techs = []): ServiceLocation
    {
        return $this->inTenant($this->tenant, function () use ($name, $techs) {
            $loc = ServiceLocation::create(['name' => $name, 'pincodes' => '560001, 560002']);
            $loc->technicians()->sync(collect($techs)->pluck('id'));

            return $loc;
        });
    }

    /** Gives a technician $n open jobs. */
    private function load(User $tech, int $n): void
    {
        $customer = $this->makeCustomer($this->tenant);
        for ($i = 0; $i < $n; $i++) {
            $this->makeJob($this->tenant, $customer, $tech);
        }
    }

    private function createJob(array $extra = []): \Illuminate\Testing\TestResponse
    {
        $customer = $this->makeCustomer($this->tenant);

        return $this->actingAsUser($this->admin)->postJson('/api/v1/jobs', array_merge([
            'customer_id' => $customer->id, 'priority' => 'medium', 'call_type' => 'crm_call',
        ], $extra));
    }

    public function test_admin_manages_locations_and_links_technicians(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $this->actingAsUser($this->admin)->postJson('/api/v1/master/service-locations', [
            'name' => 'Koramangala', 'pincodes' => '560034 560095,560034', 'technician_ids' => [$tech->id],
        ])->assertCreated()->assertJsonPath('data.pincodes', '560034, 560095')->assertJsonPath('data.technicians_count', 1);

        $this->getJson('/api/v1/master/lookups')->assertOk()->assertJsonPath('data.service_locations.0.name', 'Koramangala');

        // Linking from the technician side as well.
        $other = $this->location('Indiranagar');
        $this->patchJson("/api/v1/users/{$tech->id}", ['service_location_ids' => [$other->id]])
            ->assertOk()->assertJsonCount(1, 'data.service_locations')->assertJsonPath('data.service_locations.0.name', 'Indiranagar');
    }

    public function test_auto_assign_picks_the_least_loaded_technician_in_the_location(): void
    {
        $busy = $this->makeUser($this->tenant, Role::Technician);
        $free = $this->makeUser($this->tenant, Role::Technician);
        $elsewhere = $this->makeUser($this->tenant, Role::Technician); // no jobs, but not in this location
        $loc = $this->location('Jayanagar', [$busy, $free]);
        $this->location('Whitefield', [$elsewhere]);
        $this->load($busy, 5);
        $this->load($free, 3);

        $this->createJob(['service_location_id' => $loc->id, 'auto_assign' => true])
            ->assertCreated()
            ->assertJsonPath('data.assigned_technician_id', $free->id)
            ->assertJsonPath('data.service_location.name', 'Jayanagar')
            ->assertJsonPath('meta.auto_assign.open_jobs', 3);
    }

    public function test_technicians_at_the_limit_are_skipped_and_job_stays_unassigned_when_nobody_is_free(): void
    {
        $this->tenant->update(['settings' => ['auto_assign_max_jobs' => 10]]);
        $full = $this->makeUser($this->tenant, Role::Technician);
        $loc = $this->location('HSR Layout', [$full]);
        $this->load($full, 10);

        $res = $this->createJob(['service_location_id' => $loc->id, 'auto_assign' => true])->assertCreated();
        $res->assertJsonPath('data.assigned_technician_id', null)->assertJsonPath('meta.auto_assign.assigned', false);
        $this->assertStringContainsString('10 or more open jobs', $res->json('message'));

        // One under the limit is eligible again.
        $this->tenant->update(['settings' => ['auto_assign_max_jobs' => 11]]);
        $this->forgetGuards();
        $this->createJob(['service_location_id' => $loc->id, 'auto_assign' => true])
            ->assertJsonPath('data.assigned_technician_id', $full->id);
    }

    public function test_on_duty_only_setting_and_manual_auto_assign_endpoint(): void
    {
        $this->tenant->update(['settings' => ['auto_assign_on_duty_only' => true]]);
        $off = $this->makeUser($this->tenant, Role::Technician);
        $loc = $this->location('BTM', [$off]);

        $jobId = $this->createJob(['service_location_id' => $loc->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/jobs/{$jobId}/auto-assign")->assertStatus(422)->assertJsonValidationErrors('technician');

        $off->forceFill(['punch_status' => 'in'])->save();
        $this->postJson("/api/v1/jobs/{$jobId}/auto-assign")->assertOk()->assertJsonPath('data.assigned_technician_id', $off->id);
        $this->assertDatabaseHas('job_status_history', ['job_id' => $jobId, 'remarks' => "Auto-assigned to {$off->name} (0 open jobs at the time)"]);
    }

    public function test_location_used_by_jobs_is_deactivated_not_deleted(): void
    {
        $loc = $this->location('Hebbal');
        $this->createJob(['service_location_id' => $loc->id])->assertCreated();

        $this->deleteJson("/api/v1/master/service-locations/{$loc->id}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('service_locations', ['id' => $loc->id, 'is_active' => false]);
    }
}
