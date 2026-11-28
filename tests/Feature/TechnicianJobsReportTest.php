<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ServiceLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Technician-wise visits with where they happened, compared with the customer's saved location. */
class TechnicianJobsReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    private function visit(User $tech, array $customer, array $visit): void
    {
        $this->inTenant($this->tenant, function () use ($tech, $customer, $visit) {
            $area = ServiceLocation::firstOrCreate(['name' => 'Kakkanad']);
            $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant, $customer + ['phone' => '98'.random_int(10000000, 99999999)]), $tech, ['service_location_id' => $area->id]);
            $job->visits()->create($visit + ['technician_id' => $tech->id, 'visit_date' => now()->toDateString(), 'service_type' => 'on_site',
                'start_time' => now()->subHour(), 'end_time' => now(), 'duration_seconds' => 3600, 'status' => 'completed']);
        });
    }

    public function test_visits_are_listed_by_technician_with_locations_and_distance(): void
    {
        $anu = $this->makeUser($this->tenant, Role::Technician, ['name' => 'Anu']);
        $babu = $this->makeUser($this->tenant, Role::Technician, ['name' => 'Babu']);
        // Started at the customer's door.
        $this->visit($babu, ['name' => 'Near', 'lat' => 10.0159, 'lng' => 76.3419, 'address' => '12 MG Road', 'city' => 'Kochi'], ['location_lat' => 10.0160, 'location_lng' => 76.3420, 'end_lat' => 10.0160, 'end_lng' => 76.3420]);
        // Started about 1.1 km away.
        $this->visit($anu, ['name' => 'Far', 'lat' => 10.0159, 'lng' => 76.3419], ['location_lat' => 10.0259, 'location_lng' => 76.3419]);
        // On site but no GPS.
        $this->visit($anu, ['name' => 'Blank'], []);

        $this->actingAsUser($this->admin);
        $data = $this->getJson('/api/v1/reports/technician-jobs')->assertOk()->json('data');

        $this->assertSame(['Anu', 'Anu', 'Babu'], array_column($data['rows'], 'technician'));
        $near = collect($data['rows'])->firstWhere('customer', 'Near');
        $far = collect($data['rows'])->firstWhere('customer', 'Far');
        $this->assertSame('12 MG Road, Kochi', $near['address']);
        $this->assertSame('Kakkanad', $near['area']);
        $this->assertSame('10.016000, 76.342000', $near['start_gps']);
        $this->assertLessThan(30, $near['distance']);
        $this->assertFalse($near['_flag']);
        $this->assertEqualsWithDelta(1112, $far['distance'], 15);
        $this->assertTrue($far['_flag']);
        $this->assertSame('location', collect($data['columns'])->firstWhere('key', 'start_gps')['type']);

        // One technician only.
        $this->assertSame(['Babu'], array_column($this->getJson("/api/v1/reports/technician-jobs?technician_id={$babu->id}")->json('data.rows'), 'technician'));
        $this->get('/api/v1/reports/technician-jobs/export?format=xlsx')->assertOk();
    }
}
