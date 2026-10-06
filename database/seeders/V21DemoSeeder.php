<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\EmployeeProfile;
use App\Models\FundTransfer;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\PunchLog;
use App\Models\Tenant;
use App\Models\UpiAccount;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

/**
 * v2.1 demo data for the "demo" company: UPI ID, branch geofences, opening balances,
 * holidays, salaries, five weeks of punches, leave and last month's payroll.
 * Safe to run again â€“ it skips when the demo company already has v2.1 data.
 */
class V21DemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->isProduction() && ! env('ALLOW_DEMO_SEED'), 403, 'Demo data must not be seeded in production.');
        $tenant = Tenant::where('slug', 'demo')->first();
        if (! $tenant) {
            $this->command?->warn('No demo company â€“ run DemoSeeder first.');

            return;
        }
        app(TenantContext::class)->runAs($tenant->id, function () use ($tenant) {
            if (UpiAccount::exists()) {
                $this->command?->warn('Demo company already has v2.1 data â€“ skipping.');

                return;
            }
            $this->seed($tenant);
        });
        $this->command?->info('v2.1 demo data added (UPI, geofence, HR, payroll).');
    }

    private function seed(Tenant $tenant): void
    {
        mt_srand(21);
        $tz = $tenant->timezone;
        $now = CarbonImmutable::now($tz);

        // Geofences (Indiranagar HQ, Hebbal service point) and a UPI ID.
        $branches = Branch::orderBy('id')->get();
        $coords = [[12.9784, 77.6408], [13.0358, 77.5970]];
        foreach ($branches as $i => $b) {
            [$lat, $lng] = $coords[$i] ?? $coords[0];
            $b->update(['lat' => $lat, 'lng' => $lng, 'geofence_radius' => 300]);
        }
        UpiAccount::create(['name' => 'CoolCare UPI', 'vpa' => 'coolcare@demoupi', 'payee_name' => 'CoolCare Services Pvt Ltd', 'is_default' => true]);

        $tenant->update(['settings' => array_replace_recursive($tenant->mergedSettings(), [
            'attendance' => ['geofence' => 'flag'],
            'books' => ['opening_date' => $now->startOfMonth()->subMonth()->toDateString(), 'opening_cash' => 2500000, 'opening_bank' => 40000000],
        ])]);

        foreach ([['2026-09-14', 'Ganesh Chaturthi'], ['2026-10-02', 'Gandhi Jayanti'], ['2026-10-20', 'Dussehra'], ['2026-11-08', 'Deepavali'], ['2026-11-01', 'Kannada Rajyotsava'], ['2026-12-25', 'Christmas']] as [$date, $name]) {
            Holiday::firstOrCreate(['date' => $date], ['name' => $name, 'is_active' => true]);
        }

        // Salaries.
        $salaries = ['admin' => 8000000, 'coordinator' => 3500000, 'accountant' => 3200000, 'technician' => 2400000];
        $staff = User::inTenant()->where('role', '!=', Role::Customer->value)->where('status', 'active')->orderBy('id')->get();
        foreach ($staff as $i => $u) {
            $gross = $salaries[$u->role->value] + ($u->role === Role::Technician ? $i * 100000 : 0);
            EmployeeProfile::create([
                'user_id' => $u->id, 'employee_code' => sprintf('CC%03d', $i + 1), 'designation' => $u->role === Role::Technician ? 'Service Technician' : $u->role->label(),
                'department' => $u->role === Role::Technician ? 'Field service' : 'Office', 'date_of_joining' => $now->subYears(1 + $i % 3)->subDays(40 * $i)->toDateString(),
                'monthly_salary' => $gross, 'weekly_offs' => [7],
                'components' => [
                    ['name' => 'Basic', 'type' => 'earning', 'amount' => (int) round($gross * 0.5)],
                    ['name' => 'HRA', 'type' => 'earning', 'amount' => (int) round($gross * 0.2)],
                    ['name' => 'PF (employee)', 'type' => 'deduction', 'amount' => 180000],
                    ['name' => 'Professional tax', 'type' => 'deduction', 'amount' => 20000],
                ],
                'bank_name' => 'HDFC Bank', 'bank_account' => '5010'.mt_rand(10000000, 99999999), 'ifsc' => 'HDFC0001234',
            ]);
        }

        // Leave.
        $types = LeaveType::pluck('id', 'code');
        $techs = $staff->where('role', Role::Technician)->values();
        $admin = $staff->firstWhere('role', Role::Admin);
        $lastMonth = $now->startOfMonth()->subMonth();
        $approved = [];
        if ($techs->count() >= 2) {
            $from = $lastMonth->addDays(9);
            $approved[$techs[1]->id] = [$from->toDateString(), $from->addDay()->toDateString()];
            LeaveRequest::create(['user_id' => $techs[1]->id, 'leave_type_id' => $types['CL'], 'from_date' => $from, 'to_date' => $from->addDay(), 'days' => 2, 'reason' => 'Family function', 'status' => 'approved', 'decided_by' => $admin?->id, 'decided_at' => $from->subDays(3)]);
            $sick = $now->subDays(3);
            $approved[$techs[0]->id] = [$sick->toDateString(), $sick->toDateString()];
            LeaveRequest::create(['user_id' => $techs[0]->id, 'leave_type_id' => $types['SL'], 'from_date' => $sick, 'to_date' => $sick, 'days' => 1, 'reason' => 'Fever', 'status' => 'approved', 'decided_by' => $admin?->id, 'decided_at' => $sick]);
            $next = $now->next('Monday')->addWeek();
            LeaveRequest::create(['user_id' => $techs->last()->id, 'leave_type_id' => $types['CL'], 'from_date' => $next, 'to_date' => $next->addDays(2), 'days' => 3, 'reason' => 'Going to my native place for a wedding', 'status' => 'pending']);
        }

        // Five weeks of punches (Monâ€“Sat), with the odd late day, absence and field punch-in.
        $holidays = Holiday::pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        foreach ($staff as $u) {
            $home = $branches->firstWhere('id', $u->branch_id) ?? $branches->first();
            foreach (CarbonPeriod::create($lastMonth->toDateString(), $now->subDay()->toDateString()) as $day) {
                $d = $day->format('Y-m-d');
                $onLeave = isset($approved[$u->id]) && $d >= $approved[$u->id][0] && $d <= $approved[$u->id][1];
                if ($day->isSunday() || in_array($d, $holidays, true) || $onLeave || mt_rand(1, 100) <= 4) {
                    continue;
                }
                $in = CarbonImmutable::parse($d.' 09:00', $tz)->addMinutes(mt_rand(-10, 35));
                $out = CarbonImmutable::parse($d.' 18:00', $tz)->addMinutes(mt_rand(-20, 60));
                $field = $u->role === Role::Technician && mt_rand(1, 100) <= 15;
                $jitter = fn () => (mt_rand(-150, 150) / 100000);
                foreach ([['in', $in, $field], ['out', $out, false]] as [$type, $at, $outside]) {
                    $lat = $home->lat + ($outside ? 0.03 : $jitter());
                    $lng = $home->lng + ($outside ? 0.02 : $jitter());
                    $distance = (int) round(\App\Support\Geo::distance($lat, $lng, $home->lat, $home->lng));
                    PunchLog::create([
                        'user_id' => $u->id, 'type' => $type, 'lat' => $lat, 'lng' => $lng, 'accuracy' => mt_rand(8, 40),
                        'branch_id' => $home->id, 'distance_m' => $distance, 'within_fence' => $distance <= 300,
                        'source' => $u->role === Role::Technician ? 'mobile' : 'web', 'created_at' => $at->utc(),
                    ]);
                }
            }
            $u->forceFill(['punch_status' => 'out', 'punched_at' => $now->subDay()->setTime(18, 0)->utc()])->save();
        }

        // Money moved from cash to bank.
        FundTransfer::create(['transfer_date' => $now->subDays(6)->toDateString(), 'direction' => 'cash_to_bank', 'amount' => 2000000, 'reference_no' => 'DEP-'.mt_rand(1000, 9999), 'notes' => 'Weekly cash deposit', 'created_by' => $admin?->id]);

        // Last month's payroll, finalized and paid on the 1st.
        if ($admin && ! PayrollRun::exists()) {
            $payroll = app(PayrollService::class);
            $run = $payroll->generate($tenant, $lastMonth->format('Y-m'), $admin);
            $payroll->finalize($run);
            $payroll->pay($run->fresh(), 'bank_transfer', $now->startOfMonth()->toDateString(), $admin);
        }
    }
}
