<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shifts identify late arrivals, early leaving and extra time. They only flag: pay is unaffected.
 * 2 Nov 2026 is a Monday; times below are in the company's timezone (Asia/Kolkata).
 */
class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->makeTenant();
        $this->ctx['tenant']->update(['timezone' => 'Asia/Kolkata']);
        $this->tech = $this->makeUser($this->ctx['tenant'], Role::Technician);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shift(array $attributes = []): Shift
    {
        return $this->inTenant($this->ctx['tenant'], fn () => Shift::create($attributes + ['name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 10, 'is_default' => true]));
    }

    private function punchAt(string $when, string $type)
    {
        Carbon::setTestNow(Carbon::parse($when, 'Asia/Kolkata'));

        return $this->forgetGuards()->actingAsUser($this->tech)->postJson('/api/v1/punch', ['type' => $type, 'source' => 'mobile'])->assertOk();
    }

    private function day(string $date): array
    {
        $month = substr($date, 0, 7);

        return collect($this->forgetGuards()->actingAsUser($this->tech)->getJson("/api/v1/my/attendance?month={$month}")->assertOk()->json('data.days'))->firstWhere('date', $date);
    }

    public function test_office_manages_shifts_and_end_must_follow_start(): void
    {
        $this->actingAsUser($this->ctx['admin']);
        $body = ['name' => 'Morning', 'start_time' => '08:00', 'end_time' => '16:00', 'grace_minutes' => 5, 'is_default' => true];
        $first = $this->postJson('/api/v1/master/shifts', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/master/shifts', ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00'])->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson('/api/v1/master/shifts', ['name' => 'Bad', 'start_time' => '25:00', 'end_time' => '26:00'])->assertStatus(422);

        // One default: a new one replaces the old.
        $this->postJson('/api/v1/master/shifts', ['name' => 'Late', 'start_time' => '12:00', 'end_time' => '20:00', 'is_default' => true])->assertCreated();
        $this->assertDatabaseHas('shifts', ['id' => $first, 'is_default' => false]);
        $this->getJson('/api/v1/master/lookups')->assertOk()->assertJsonCount(2, 'data.shifts');

        $this->forgetGuards()->actingAsUser($this->tech)->postJson('/api/v1/master/shifts', $body + ['name' => 'Mine'])->assertForbidden();
    }

    public function test_a_late_arrival_is_flagged_past_the_grace_period_and_told_to_the_technician(): void
    {
        $this->shift();

        // Within grace: on time.
        $this->punchAt('2026-11-02 09:08', 'in')->assertJsonPath('message', 'Punched in. Have a great day!');
        $this->assertSame(0, $this->day('2026-11-02')['late_minutes']);
        $this->punchAt('2026-11-02 18:00', 'out');

        // Past grace: counted from the shift's start, not from the end of the grace.
        $late = $this->punchAt('2026-11-03 09:18', 'in');
        $this->assertStringContainsString('18 min late', $late->json('message'));
        $day = $this->day('2026-11-03');
        $this->assertSame(18, $day['late_minutes']);
        $this->assertSame('General', $day['shift']['name']);

        // Back from a break the same day: not late again.
        $this->punchAt('2026-11-03 13:00', 'out');
        $this->assertSame('Punched in. Have a great day!', $this->punchAt('2026-11-03 13:30', 'in')->json('message'));
        $this->assertSame(18, $this->day('2026-11-03')['late_minutes']);

        // A long delay reads in hours.
        $this->punchAt('2026-11-03 18:00', 'out');
        $this->assertStringContainsString('1h 30m late', $this->punchAt('2026-11-04 10:30', 'in')->json('message'));
    }

    public function test_early_leaving_and_extra_time(): void
    {
        $this->shift();

        $this->punchAt('2026-11-02 09:00', 'in');
        // Still on duty, or out for a break: judged on the last punch out only.
        $this->assertSame(0, $this->day('2026-11-02')['early_minutes']);
        $this->punchAt('2026-11-02 17:00', 'out');
        $this->assertSame(60, $this->day('2026-11-02')['early_minutes']);
        $this->assertSame(0, $this->day('2026-11-02')['extra_minutes']);

        $this->punchAt('2026-11-03 09:00', 'in');
        $this->punchAt('2026-11-03 19:30', 'out');
        $day = $this->day('2026-11-03');
        $this->assertSame(0, $day['early_minutes']);
        $this->assertSame(90, $day['extra_minutes']);
    }

    public function test_an_employees_own_shift_beats_the_default_and_changes_do_not_rewrite_history(): void
    {
        $this->shift();
        $own = $this->shift(['name' => 'Second', 'start_time' => '13:00', 'end_time' => '21:00', 'is_default' => false]);

        $this->forgetGuards()->actingAsUser($this->ctx['admin'])->putJson("/api/v1/hr/employees/{$this->tech->id}", ['monthly_salary' => 2000000, 'shift_id' => $own->id])->assertOk();
        $this->assertDatabaseHas('employee_profiles', ['user_id' => $this->tech->id, 'shift_id' => $own->id]);

        // 12:30 is fine for 13:00, though hours after the default's 09:00.
        $this->punchAt('2026-11-02 12:30', 'in');
        $this->assertSame(0, $this->day('2026-11-02')['late_minutes']);
        $this->punchAt('2026-11-02 21:00', 'out');
        $this->punchAt('2026-11-03 13:45', 'in');
        $this->assertSame(45, $this->day('2026-11-03')['late_minutes']);

        // Moving the shift later leaves that day as it was.
        $this->inTenant($this->ctx['tenant'], fn () => $own->update(['start_time' => '14:00', 'end_time' => '22:00']));
        $this->assertSame(45, $this->day('2026-11-03')['late_minutes']);
    }

    public function test_days_off_and_people_without_a_shift_are_not_flagged(): void
    {
        // No shift anywhere: nothing to measure against.
        $this->punchAt('2026-11-02 11:00', 'in');
        $day = $this->day('2026-11-02');
        $this->assertSame(0, $day['late_minutes']);
        $this->assertNull($day['shift']);
        $this->punchAt('2026-11-02 12:00', 'out');

        // Sunday is the weekly off: not late, and the time worked is all extra.
        $this->shift();
        $this->punchAt('2026-11-08 11:00', 'in');
        $this->punchAt('2026-11-08 13:00', 'out');
        $day = $this->day('2026-11-08');
        $this->assertSame(0, $day['late_minutes']);
        $this->assertSame(0, $day['early_minutes']);
        $this->assertSame(120, $day['extra_minutes']);
    }

    public function test_flags_reach_the_register_daily_view_and_report_but_not_pay(): void
    {
        $this->shift();
        $this->punchAt('2026-11-02 09:30', 'in');
        $this->punchAt('2026-11-02 17:30', 'out');
        Carbon::setTestNow(Carbon::parse('2026-11-02 20:00', 'Asia/Kolkata'));

        $this->forgetGuards()->actingAsUser($this->ctx['admin']);
        $daily = $this->getJson('/api/v1/hr/attendance/daily?date=2026-11-02')->assertOk();
        $this->assertSame(1, $daily->json('meta.late'));
        $row = collect($daily->json('data'))->firstWhere('user.id', $this->tech->id);
        $this->assertSame(30, $row['day']['late_minutes']);
        $this->assertSame(30, $row['day']['early_minutes']);

        $reg = collect($this->getJson('/api/v1/hr/attendance/register?month=2026-11')->assertOk()->json('data.rows'))->firstWhere('user.id', $this->tech->id);
        $this->assertSame(30, $reg['days']['2026-11-02']['late_minutes']);
        $this->assertSame(1, $reg['summary']['late_days']);
        $this->assertSame(30, $reg['summary']['late_minutes']);
        $this->assertSame(1, $reg['summary']['early_days']);
        // A late day still counts as a full day present, so payroll doesn't change.
        $this->assertSame(1.0, (float) $reg['summary']['present']);

        $report = collect($this->getJson('/api/v1/reports/attendance?from=2026-11-01&to=2026-11-30')->assertOk()->json('data.rows'))->firstWhere('name', $this->tech->name);
        $this->assertSame(1, $report['late_days']);
        $this->assertSame(1, $report['early_days']);

        // The technician's home screen knows the shift.
        $this->forgetGuards()->actingAsUser($this->tech)->getJson('/api/v1/dashboard/technician')->assertOk()->assertJsonPath('data.attendance.shift.name', 'General');
    }

    public function test_shift_from_another_company_cannot_be_assigned(): void
    {
        $other = $this->makeTenant('other');
        $foreign = $this->inTenant($other['tenant'], fn () => Shift::create(['name' => 'Theirs', 'start_time' => '09:00', 'end_time' => '18:00']));
        $this->forgetGuards()->actingAsUser($this->ctx['admin'])->putJson("/api/v1/hr/employees/{$this->tech->id}", ['monthly_salary' => 100, 'shift_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('shift_id');
        $this->inTenant($this->ctx['tenant'], fn () => $this->assertNull(EmployeeProfile::where('user_id', $this->tech->id)->first()));
    }
}
