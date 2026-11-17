<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\JobVisit;
use App\Models\PunchLog;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Workforce → Trips: a technician's day rebuilt from punches and visits. */
class TripTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->tech = $this->makeUser($this->tenant, Role::Technician, ['name' => 'Ravi']);
    }

    /** A time on $date in the company's timezone, as UTC. */
    private function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("$date $time", $this->tenant->timezone)->utc();
    }

    private function punch(string $type, CarbonImmutable $at, ?float $lat, ?float $lng): void
    {
        $this->inTenant($this->tenant, fn () => PunchLog::create(['user_id' => $this->tech->id, 'type' => $type, 'lat' => $lat, 'lng' => $lng, 'source' => 'app', 'created_at' => $at]));
    }

    private function visit(CarbonImmutable $start, CarbonImmutable $end, ?float $lat, ?float $lng, string $status = 'completed'): void
    {
        $job = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant, ['name' => 'Asha', 'city' => 'Kochi']), $this->tech);
        $this->inTenant($this->tenant, fn () => JobVisit::create([
            'job_id' => $job->id, 'technician_id' => $this->tech->id, 'visit_date' => $start->toDateString(), 'service_type' => $lat === null ? 'tele_call' : 'on_site',
            'start_time' => $start, 'end_time' => $end, 'duration_seconds' => $start->diffInSeconds($end), 'status' => $status,
            'location_lat' => $lat, 'location_lng' => $lng, 'end_lat' => $lat, 'end_lng' => $lng,
        ]));
    }

    public function test_a_day_is_rebuilt_stop_by_stop_with_distance_and_time(): void
    {
        $day = '2026-11-10';
        // 0.01° of latitude is about 1.11 km: office → first customer → second customer → office.
        $this->punch('in', $this->at($day, '09:00'), 10.00, 76.30);
        $this->visit($this->at($day, '09:30'), $this->at($day, '10:30'), 10.01, 76.30);
        $this->visit($this->at($day, '11:00'), $this->at($day, '12:00'), 10.03, 76.30);
        $this->visit($this->at($day, '12:10'), $this->at($day, '12:20'), null, null, 'pending'); // a phone call: no place
        $this->punch('out', $this->at($day, '18:00'), 10.00, 76.30);

        $this->actingAsUser($this->admin);
        $data = $this->getJson("/api/v1/hr/trips/{$this->tech->id}?date={$day}")->assertOk()->json('data');
        $this->assertSame(['punch_in', 'visit', 'visit', 'visit', 'punch_out'], array_column($data['stops'], 'kind'));
        $this->assertNull($data['stops'][0]['leg']);
        $this->assertEqualsWithDelta(1112, $data['stops'][1]['leg']['distance_m'], 5);
        $this->assertSame(1800, $data['stops'][1]['leg']['seconds']);
        $this->assertSame(3600, $data['stops'][1]['on_site_seconds']);
        $this->assertEqualsWithDelta(2224, $data['stops'][2]['leg']['distance_m'], 8);
        // The phone call has no place, so the legs into and out of it have no distance.
        $this->assertNull($data['stops'][3]['leg']['distance_m']);
        $this->assertNull($data['stops'][4]['leg']['distance_m']);

        $s = $data['summary'];
        $this->assertSame(3, $s['visits']);
        $this->assertSame(2, $s['completed']);
        $this->assertEqualsWithDelta(3336, $s['distance_m'], 12);
        $this->assertSame(1800 + 1800, $s['travel_seconds']);
        $this->assertSame(3600 + 3600 + 600, $s['on_site_seconds']);
        $this->assertSame(2, $s['unlocated_legs']);
        $this->assertStringStartsWith('https://www.google.com/maps/dir/10,76.3/10.01,76.3/', $data['map_url']);
    }

    public function test_the_period_lists_each_technician_day_and_totals(): void
    {
        $this->punch('in', $this->at('2026-11-10', '09:00'), 10.00, 76.30);
        $this->visit($this->at('2026-11-10', '09:30'), $this->at('2026-11-10', '10:00'), 10.01, 76.30);
        $this->visit($this->at('2026-11-11', '09:30'), $this->at('2026-11-11', '10:00'), 10.02, 76.30);
        $idle = $this->makeUser($this->tenant, Role::Technician, ['name' => 'Arjun']);

        $this->actingAsUser($this->admin);
        $res = $this->getJson('/api/v1/hr/trips?from=2026-11-10&to=2026-11-11')->assertOk();
        $res->assertJsonCount(2, 'data.days')->assertJsonCount(1, 'data.technicians')
            ->assertJsonPath('data.days.0.date', '2026-11-11')->assertJsonPath('data.technicians.0.name', 'Ravi')
            ->assertJsonPath('data.technicians.0.days', 2)->assertJsonPath('data.totals.visits', 2);
        $this->getJson("/api/v1/hr/trips?from=2026-11-10&to=2026-11-11&user_id={$idle->id}")->assertJsonCount(0, 'data.days');
        $this->getJson('/api/v1/hr/trips?from=2026-01-01&to=2026-11-11')->assertStatus(422);

        // A day with nothing recorded is an empty route, not an error.
        $this->getJson("/api/v1/hr/trips/{$this->tech->id}?date=2026-11-09")->assertOk()->assertJsonCount(0, 'data.stops')->assertJsonPath('data.map_url', null);

        // Technicians don't see the office's trips screen.
        $this->forgetGuards()->actingAsUser($this->tech)->getJson('/api/v1/hr/trips')->assertForbidden();
    }
}
