<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ComplaintSummary;
use App\Models\ComplaintType;
use App\Models\ExpenseCategory;
use App\Models\LeaveType;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Go-live data for AJ CLIMATE CARE: master data and staff accounts.
 * Safe to run again - existing records (matched by name / login) are left as they are.
 *
 *   COMPANY_SLUG=demo STAFF_TEMP_PASSWORD=... php artisan db:seed --class=AjClimateCareSeeder
 *
 * Staff get a generated login (<name>@ajclimatecare.local) and a temporary password they must
 * change at first sign-in. Add real phones/emails from the Users screen.
 */
class AjClimateCareSeeder extends Seeder
{
    private const LOGIN_DOMAIN = 'ajclimatecare.local';

    private const TECHNICIANS = [
        'VIPIN ANTONY', 'DINTO FRANCIS', 'VIPIN KK', 'SANOOP PS', 'ANANDHU SANTHOSH', 'SHYAM KRISHNA',
        'MUHAMMED ANWAR T', 'SHYAMROOP VK', 'AMAL PS', 'AMAL SP', 'AFTHAB A', 'MUHAMMED YASIR', 'NOEL G SAJI',
        'MUHAMMED SIYAD', 'MUHAMMED RISHAD', 'MUHAMMED SHAMEEMUDHEEN', 'JAMSHEER P', 'MUHSIN', 'SUSHANTH M',
        'MUHAMMED RISAL', 'ANEESH PV', 'MUHAMMED AFSAL TV',
    ];

    /** [name, role, login]. ANEESH PV (office) is a different person from the technician of the same name. */
    private const OFFICE_STAFF = [
        ['JITHIN TS', Role::Admin, 'jithin.ts'],                 // admin with accounting
        ['SOORAJ MP', Role::Accountant, 'sooraj.mp'],            // stock management
        ['ABHINAV TK', Role::Accountant, 'abhinav.tk'],          // service tracking and stock management
        ['AMRUTHA TV', Role::Coordinator, 'amrutha.tv'],         // service tracking
        ['PREETHA CV', Role::Coordinator, 'preetha.cv'],
        ['AKSHAYA SAJAN', Role::Coordinator, 'akshaya.sajan'],
        ['ANEESH PV', Role::Coordinator, 'aneesh.pv.office'],
    ];

    private const BRANDS = ['LLOYD', 'FORMENTY', 'SAMSUNG', 'OTHER BRAND'];

    private const PRODUCTS = [
        'INVERTER AC', 'NON INVERTER AC', 'WM TOP', 'WM FRONT', 'WM SEMI', 'MICRO OVEN', 'DISH WASHER', 'TV', 'REF',
        'DUCTABLE AC', 'CASSETE AC', 'TOWER AC',
    ];

    private const SERVICE_TYPES = [
        'PM SERVICE', 'BREAKDOWN', 'STOCK REPAIR', 'INSTALLATION', 'INSPECTION', 'PCB REPARING', 'WATER SERVICE',
        'RE-INSTALLATION', 'GAS CHARGING', 'GAS TOP UP',
    ];

    private const PART_CALL = ['PART USED', 'PART NOT USED'];

    /** Breakdown categories (AC, washing machine, refrigerator parts); repeated names are stored once. */
    private const BREAKDOWN = [
        'COMPRESSOR(AC)', 'IDU PCB', 'DISPLAY PCB', 'ODU PCB', 'ODU SENSOR', 'IDU SENSOR', 'GAS CHARGING', 'WATER LEAK',
        'FAN MOTOR', 'BLOWER MOTOR', 'SWING MOTOR', 'BLOWER FAN', 'CONDENSOR', 'COOLING COIL',
        'SPIN MOTOR(WM)', 'WASH MOTOR', 'SPIN TIMER', 'WASH TIMER', 'CAPPACITOR', 'WASH SELECTOR', 'NOBE', 'MECHANISAM',
        'DRAIN BELOW', 'SPIN BREAK', 'BELOW', 'SPIN TUB', 'WM PCB', 'MAGIC FILTER', 'DOOR SWITCH', 'SAFTY SWITCH',
        'INLET HOSE', 'OUTLET HOSE', 'WIRING KIT', 'SPIN LID', 'WASH LID', 'BASE', 'MIDDLE FRAME', 'CLUTCH ASSY',
        'INLET VALVE', 'DRAIN MOTOR',
        'DOOR EVAP(REF)', 'GASKET', 'BYMETAL', 'FUSE', 'THERMOSTAT', 'THERMOSTAT NOBE', 'COMPRESSOR', 'CONDENSOR',
        'EVAP COIL', 'CAPPACITOR', 'OLP', 'RELAY', 'TIMER', 'HEATER', 'WATER LEAK', 'LAMP', 'REF PCB', 'COUNTER PCB',
    ];

    private const ACCOUNTS = [
        'TRAVEL EXPENCE', 'FOOD EXPENCE', 'BIKE MAINTANANCE', 'ROOM FOOD', 'ROOM RENT', 'ELECTRICITY BILL',
        'OFFICE STATIONARY', 'OFFICE RENT', 'SALARY', 'SALARY ADVANCE', 'ADVANCE', 'BIKE EMI', 'TECH INSURANCE',
        'SPARE LOCAL PURCHASE', 'PCB REPAIRING',
    ];

    public function run(): void
    {
        $slug = env('COMPANY_SLUG', 'demo');
        $tenant = Tenant::where('slug', $slug)->first();
        if (! $tenant) {
            $this->command?->error("No company with slug \"{$slug}\". Set COMPANY_SLUG.");

            return;
        }

        $password = env('STAFF_TEMP_PASSWORD') ?: 'Ajcc@'.random_int(100000, 999999);

        app(TenantContext::class)->runAs($tenant->id, function () use ($tenant, $password) {
            $this->masterData();
            $created = $this->staff($tenant, $password);

            $this->command?->info("Master data seeded for {$tenant->name}.");
            if ($created) {
                $this->command?->info("{$created} staff accounts created. Temporary password (change required at first login): {$password}");
            }
        });
    }

    private function masterData(): void
    {
        $add = fn (string $model, array $names) => collect($names)->unique()->each(fn ($n) => $model::firstOrCreate(['name' => $n]));

        $add(Brand::class, self::BRANDS);
        $add(ProductCategory::class, self::PRODUCTS);
        $add(ComplaintType::class, self::SERVICE_TYPES);
        $add(ActionTakenOption::class, self::PART_CALL);
        $add(ExpenseCategory::class, self::ACCOUNTS);
        // Not tied to a service type, so they are offered for every job.
        collect(self::BREAKDOWN)->unique()->each(fn ($n) => ComplaintSummary::firstOrCreate(['name' => $n, 'complaint_type_id' => null]));
        foreach (TenantProvisioningService::DEFAULT_LEAVE_TYPES as $type) {
            LeaveType::firstOrCreate(['code' => $type['code']], $type);
        }
    }

    private function staff(Tenant $tenant, string $password): int
    {
        $branch = Branch::orderBy('id')->first() ?? Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
        $people = array_merge(
            array_map(fn ($name) => [$name, Role::Technician, Str::slug($name, '.')], self::TECHNICIANS),
            self::OFFICE_STAFF,
        );

        $created = 0;
        foreach ($people as [$name, $role, $login]) {
            $email = $login.'@'.self::LOGIN_DOMAIN;
            if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                continue;
            }
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role, 'branch_id' => $branch->id, 'status' => 'active']);
            $user->tenant_id = $tenant->id;
            $user->must_change_password = true;
            $user->save();
            $created++;
        }

        return $created;
    }
}
