<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ServiceJob;
use App\Models\ServiceLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Jobs list's one-click filters, and the service-location analysis page and dashboard preview. */
class JobFiltersAndLocationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
    }

    private function location(string $name, array $technicians = []): ServiceLocation
    {
        return $this->inTenant($this->tenant, function () use ($name, $technicians) {
            $location = ServiceLocation::create(['name' => $name, 'is_active' => true]);
            $location->technicians()->attach(collect($technicians)->pluck('id'));

            return $location;
        });
    }

    private function job(?User $tech = null, array $attributes = [], array $forced = []): ServiceJob
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant, ['phone' => '98'.random_int(10000000, 99999999)]), $tech, $attributes);
        if ($forced) {
            // Dates and statuses set directly: created_at isn't fillable and jobs normally reach these states through visits.
            $this->inTenant($this->tenant, fn () => ServiceJob::whereKey($job->id)->toBase()->update($forced));
        }

        return $job;
    }

    public function test_quick_filters_and_their_counts(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $location = $this->location('Kannur');
        $unassigned = $this->job(null, ['service_location_id' => $location->id, 'scheduled_at' => now()->addDays(3)]);
        $overdue = $this->job($tech, ['service_location_id' => $location->id, 'scheduled_at' => now()->subDays(3)]);
        $aged = $this->job($tech, ['service_location_id' => $location->id, 'scheduled_at' => now()->addDay()], ['created_at' => now()->subDays(10)]);
        $high = $this->job($tech, ['service_location_id' => $location->id, 'priority' => 'high', 'scheduled_at' => now()->addDay()]);
        $noLocation = $this->job($tech, ['scheduled_at' => now()->addDay()]);
        // Closed jobs never appear in these: it's cancelled and has no technician or location, and is old and overdue.
        $this->job(null, ['priority' => 'high', 'scheduled_at' => now()->subDays(9)], ['status' => 'cancelled', 'created_at' => now()->subDays(20)]);

        $this->actingAsUser($this->admin);
        $ids = fn (string $quick) => array_column($this->getJson("/api/v1/jobs?quick={$quick}")->assertOk()->json('data'), 'id');

        $this->assertSame([$unassigned->id], $ids('unassigned'));
        $this->assertSame([$overdue->id], $ids('overdue'));
        $this->assertSame([$aged->id], $ids('aged'));
        $this->assertSame([$high->id], $ids('high'));
        $this->assertSame([$noLocation->id], $ids('no_location'));
        $this->assertCount(6, $this->getJson('/api/v1/jobs?quick=nonsense')->json('data'));

        $this->getJson('/api/v1/jobs/quick-counts')->assertOk()
            ->assertJsonPath('data.unassigned', 1)->assertJsonPath('data.overdue', 1)->assertJsonPath('data.aged', 1)
            ->assertJsonPath('data.high', 1)->assertJsonPath('data.no_location', 1);

        // The counts stay within the other filters, and match the list.
        $this->getJson('/api/v1/jobs/quick-counts?technician_id='.$tech->id)->assertJsonPath('data.unassigned', 0)->assertJsonPath('data.overdue', 1);
        $this->assertSame([$noLocation->id], array_column($this->getJson('/api/v1/jobs?service_location_id=none&status=open')->json('data'), 'id'));
    }

    public function test_call_type_filter(): void
    {
        $walkIn = $this->job(null, ['call_type' => 'walk_in']);
        $this->job(null, ['call_type' => 'crm_call']);
        $this->actingAsUser($this->admin);

        $this->assertSame([$walkIn->id], array_column($this->getJson('/api/v1/jobs?call_type=walk_in')->json('data'), 'id'));
    }

    public function test_technicians_only_count_their_own_jobs(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $other = $this->makeUser($this->tenant, Role::Technician);
        $this->job($tech, ['priority' => 'high']);
        $this->job($other, ['priority' => 'high']);

        $this->actingAsUser($tech)->getJson('/api/v1/jobs/quick-counts')->assertOk()->assertJsonPath('data.high', 1);
    }

    public function test_location_analysis_shows_allocation_and_period(): void
    {
        $vipin = $this->makeUser($this->tenant, Role::Technician, ['name' => 'Vipin']);
        $dinto = $this->makeUser($this->tenant, Role::Technician, ['name' => 'Dinto']);
        $this->punchIn($vipin);
        $kannur = $this->location('Kannur', [$vipin]);
        $this->location('Empty place');

        $this->job($vipin, ['service_location_id' => $kannur->id, 'scheduled_at' => now()->addDay()]);
        $this->job(null, ['service_location_id' => $kannur->id, 'scheduled_at' => now()->subDays(2)]);
        // Worked by someone not linked to the location, and old.
        $this->job($dinto, ['service_location_id' => $kannur->id, 'scheduled_at' => now()->addDay()], ['status' => 'part_pending', 'created_at' => now()->subDays(40)]);
        // Finished in the period, two days after it was logged.
        $this->job($vipin, ['service_location_id' => $kannur->id], ['status' => 'completed', 'created_at' => now()->subDays(2), 'completed_at' => now()]);
        $this->job(null, ['service_location_id' => $kannur->id], ['status' => 'cancelled']);
        $this->job(null, ['scheduled_at' => now()->addDay()]);

        // The period is in the company's timezone, which can already be tomorrow in UTC terms.
        $today = now($this->tenant->timezone);
        $data = $this->actingAsUser($this->admin)->getJson('/api/v1/jobs/location-analysis?from='.$today->copy()->subDays(5)->toDateString().'&to='.$today->copy()->toDateString())->assertOk()->json('data');

        $byName = collect($data['locations'])->keyBy('name');
        $row = $byName['Kannur'];
        $this->assertSame(3, $row['active']);
        $this->assertSame(1, $row['part_pending']);
        $this->assertSame(1, $row['unassigned']);
        $this->assertSame(1, $row['overdue']);
        $this->assertSame(1, $row['aged']);
        // Logged in the period: the open one, the unassigned one, the finished one and the cancelled one.
        $this->assertSame(4, $row['logged']);
        $this->assertSame(1, $row['completed']);
        $this->assertSame(1, $row['cancelled']);
        $this->assertSame(33, $row['completion_rate']);
        $this->assertSame(2.0, (float) $row['avg_days_to_complete']);
        $this->assertSame(1, $row['linked_technicians']);
        $this->assertSame(1, $row['on_duty']);

        $techs = collect($row['technicians'])->keyBy('name');
        $this->assertSame(1, $techs['Vipin']['active_jobs']);
        $this->assertSame(1, $techs['Vipin']['completed']);
        $this->assertTrue($techs['Vipin']['linked']);
        $this->assertSame(1, $techs['Dinto']['active_jobs']);
        $this->assertFalse($techs['Dinto']['linked']);

        // Jobs with no location get a row of their own; a location with nothing still shows.
        $none = collect($data['locations'])->firstWhere('id', null);
        $this->assertSame(1, $none['active']);
        $this->assertSame(0, $byName['Empty place']['active']);
        $this->assertSame(4, $data['totals']['active']);
        $this->assertSame(2, $data['totals']['unassigned']);
    }

    public function test_money_and_access_follow_roles(): void
    {
        $this->location('Kannur');
        $this->actingAsUser($this->admin)->getJson('/api/v1/jobs/location-analysis')->assertOk()->assertJsonPath('data.locations.0.billed', 0);

        $this->forgetGuards();
        $this->actingAsUser($this->makeUser($this->tenant, Role::Technician))->getJson('/api/v1/jobs/location-analysis')->assertForbidden();
    }

    public function test_billed_is_counted_per_location(): void
    {
        $tech = $this->makeUser($this->tenant, Role::Technician);
        $kannur = $this->location('Kannur');
        $this->location('Thalassery');
        $job = $this->job($tech, ['service_location_id' => $kannur->id]);
        $this->punchIn($tech);
        $this->actingAsUser($tech);
        $visit = $this->postJson("/api/v1/jobs/{$job->id}/visits/start", ['service_type' => 'tele_call'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/complete", ['status' => 'pending', 'service_summary' => 'Checked', 'labour_charge' => 30000, 'payment_method' => 'credit'])->assertOk();

        $this->forgetGuards();
        $rows = collect($this->actingAsUser($this->admin)->getJson('/api/v1/jobs/location-analysis')->assertOk()->json('data.locations'))->keyBy('name');
        $this->assertSame(30000, $rows['Kannur']['billed']);
        $this->assertSame(0, $rows['Thalassery']['billed']);
    }

    public function test_dashboard_preview_lists_locations_without_the_detail(): void
    {
        $kannur = $this->location('Kannur');
        $this->job(null, ['service_location_id' => $kannur->id, 'scheduled_at' => now()->addDay()]);

        $preview = $this->actingAsUser($this->admin)->getJson('/api/v1/dashboard')->assertOk()->json('data.locations');
        $this->assertSame('Kannur', $preview[0]['name']);
        $this->assertSame(1, $preview[0]['active']);
        $this->assertArrayNotHasKey('technicians', $preview[0]);
    }

    public function test_other_companies_data_stays_out(): void
    {
        $this->location('Kannur');
        ['tenant' => $other, 'admin' => $otherAdmin] = $this->makeTenant('other');
        $this->inTenant($other, fn () => ServiceLocation::create(['name' => 'Elsewhere', 'is_active' => true]));

        $names = array_column($this->actingAsUser($this->admin)->getJson('/api/v1/jobs/location-analysis')->assertOk()->json('data.locations'), 'name');
        $this->assertSame(['Kannur'], $names);
    }
}
