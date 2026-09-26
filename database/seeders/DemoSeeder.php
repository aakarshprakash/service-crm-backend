<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ComplaintType;
use App\Models\Customer;
use App\Models\Dealer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Review;
use App\Models\SubscriptionPlan;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashCloseService;
use App\Services\InventoryService;
use App\Services\JobService;
use App\Services\TenantProvisioningService;
use App\Services\VisitService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A realistic demo company ("demo") built through the real services, spread over
 * the last 30 days, so every screen and report has meaningful data.
 *
 * Logins (password Demo@12345): admin@demo.test, coordinator@demo.test,
 * accounts@demo.test, ravi@demo.test, arjun@demo.test, imran@demo.test.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'Demo@12345';

    private Carbon $today;

    public function run(): void
    {
        // Blocked in production by default - this is fake data for 22 customers, a month of
        // jobs, cash closes, etc. Set ALLOW_DEMO_SEED=true (once, then unset it) to let a
        // client-facing demo tenant be seeded deliberately.
        abort_if(app()->isProduction() && ! env('ALLOW_DEMO_SEED'), 403, 'Demo data must not be seeded in production. Set ALLOW_DEMO_SEED=true to override once.');
        config(['queue.default' => 'sync']);
        mt_srand(42);
        $this->today = Carbon::now('Asia/Kolkata')->startOfDay()->utc();

        if (Tenant::where('slug', 'demo')->exists()) {
            $this->command?->warn('Demo tenant already exists – skipping.');

            return;
        }

        Carbon::setTestNow(now()->subDays(32));
        ['tenant' => $tenant, 'admin' => $admin] = app(TenantProvisioningService::class)->provision([
            'company_name' => 'CoolCare Services Pvt Ltd',
            'slug' => 'demo',
            'name' => 'Priya Sharma',
            'email' => 'admin@demo.test',
            'phone' => '9876500001',
            'password' => self::PASSWORD,
            'plan_id' => SubscriptionPlan::where('code', 'growth')->value('id'),
            'status' => 'active',
        ]);
        $tenant->update([
            'address' => "12 MG Road, Indiranagar\nBengaluru, Karnataka 560038",
            'gstin' => '29ABCDE1234F1Z5',
            'trial_ends_at' => null,
            'settings' => array_replace_recursive($tenant->mergedSettings(), ['notifications' => ['sms' => true, 'whatsapp' => true]]),
        ]);

        app(TenantContext::class)->runAs($tenant->id, fn () => $this->seedTenant($tenant, $admin));
        Carbon::setTestNow();
        $this->command?->info('Demo company "demo" seeded. Login admin@demo.test / '.self::PASSWORD);
    }

    private function seedTenant(Tenant $tenant, User $admin): void
    {
        $main = Branch::first();
        $main->update(['name' => 'Bengaluru HQ', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560038']);
        $north = Branch::create(['name' => 'Hebbal Service Point', 'code' => 'HEB', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560024']);

        $staff = fn (string $name, string $email, Role $role, Branch $branch, string $phone) => tap(new User([
            'name' => $name, 'email' => $email, 'phone' => $phone, 'password' => self::PASSWORD, 'role' => $role, 'branch_id' => $branch->id, 'status' => 'active',
        ]), function ($u) use ($tenant) {
            $u->tenant_id = $tenant->id;
            $u->save();
        });

        $staff('Karthik Rao', 'coordinator@demo.test', Role::Coordinator, $main, '9876500002');
        $staff('Meena Iyer', 'accounts@demo.test', Role::Accountant, $main, '9876500003');
        $techs = [
            $staff('Ravi Kumar', 'ravi@demo.test', Role::Technician, $main, '9876500011'),
            $staff('Arjun Nair', 'arjun@demo.test', Role::Technician, $main, '9876500012'),
            $staff('Imran Khan', 'imran@demo.test', Role::Technician, $north, '9876500013'),
        ];

        // Master data.
        $brands = collect(['Voltas', 'LG', 'Samsung', 'Daikin', 'Whirlpool', 'Beko'])->map(fn ($n) => Brand::create(['name' => $n]));
        $cats = ProductCategory::pluck('id', 'name');
        $products = collect([
            ['Voltas', 'Air Conditioner', '183V Vectra CAZ 1.5T Split'], ['Daikin', 'Air Conditioner', 'FTKF50 Inverter 1.5T'],
            ['LG', 'Air Conditioner', 'RS-Q19YNZE Dual Inverter'], ['Samsung', 'Refrigerator', 'RT42 Double Door 415L'],
            ['Whirlpool', 'Refrigerator', 'IF INV 278 ELT'], ['LG', 'Washing Machine', 'FHM1408BDL Front Load 8kg'],
            ['Beko', 'Washing Machine', 'WTV8636XS 8kg'], ['Samsung', 'Microwave', 'CE1041DSB2 28L'],
        ])->map(fn ($p) => Product::create(['brand_id' => $brands->firstWhere('name', $p[0])->id, 'category_id' => $cats[$p[1]], 'model_name' => $p[2]]));
        $dealers = collect(['Reliance Digital', 'Croma', 'Vijay Sales', 'Local Dealer'])->map(fn ($n) => Dealer::create(['name' => $n, 'phone' => '080'.mt_rand(2000000, 9999999)]));
        $supplier = Supplier::create(['name' => 'Spares India Distributors', 'contact' => 'Suresh', 'phone' => '9845012345', 'gstin' => '29AAACS1234K1Z2']);

        // Inventory with opening stock.
        $inventory = app(InventoryService::class);
        $items = collect([
            ['CAP-35MFD', 'Capacitor 35+5 MFD', 'spare', 'AC Parts', 'nos', 45000, 5, 30000],
            ['PCB-VOL-01', 'Indoor PCB – Voltas Inverter', 'spare', 'AC Parts', 'nos', 480000, 2, 350000],
            ['FAN-MTR-ID', 'Indoor Fan Motor', 'spare', 'AC Parts', 'nos', 320000, 2, 240000],
            ['REM-UNIV', 'Universal AC Remote', 'spare', 'AC Parts', 'nos', 60000, 5, 35000],
            ['THERMO-FR', 'Refrigerator Thermostat', 'spare', 'Fridge Parts', 'nos', 85000, 3, 55000],
            ['DEF-HTR', 'Defrost Heater', 'spare', 'Fridge Parts', 'nos', 110000, 3, 70000],
            ['SPIN-MTR', 'Spin Motor – Semi Auto', 'spare', 'Washer Parts', 'nos', 240000, 2, 170000],
            ['DRAIN-PMP', 'Drain Pump', 'spare', 'Washer Parts', 'nos', 150000, 3, 95000],
            ['GAS-R32', 'Refrigerant Gas R32', 'consumable', 'Gas & Chemicals', 'kg', 180000, 3, 110000],
            ['GAS-R410', 'Refrigerant Gas R410A', 'consumable', 'Gas & Chemicals', 'kg', 220000, 3, 140000],
            ['CU-PIPE-14', 'Copper Pipe 1/4"', 'consumable', 'Wiring & Fasteners', 'metre', 45000, 10, 30000],
            ['INS-TAPE', 'Insulation Tape', 'consumable', 'Wiring & Fasteners', 'nos', 4000, 20, 2000],
            ['COIL-CLN', 'Coil Cleaning Chemical', 'consumable', 'Gas & Chemicals', 'ltr', 60000, 2, 35000],
        ])->map(function ($i) use ($inventory, $main, $north, $supplier, $admin) {
            $item = InventoryItem::create(['code' => $i[0], 'name' => $i[1], 'type' => $i[2], 'category' => $i[3], 'unit_of_measure' => $i[4], 'unit_price' => $i[5], 'reorder_level' => $i[6]]);
            $inventory->stockIn($main->id, $item, $i[4] === 'nos' ? 12 : 25, $i[7], ['supplier_id' => $supplier->id, 'invoice_ref' => 'SID/2026/0891', 'created_by' => $admin->id]);
            $inventory->stockIn($north->id, $item, $i[4] === 'nos' ? 4 : 8, $i[7], ['supplier_id' => $supplier->id, 'invoice_ref' => 'SID/2026/0892', 'created_by' => $admin->id]);

            return $item;
        });

        // Customers with products.
        $names = ['Anita Desai', 'Rahul Menon', 'Sneha Reddy', 'Vikram Singh', 'Lakshmi Narayan', 'Farhan Ali', 'Deepa Kulkarni', 'Suresh Babu',
            'Kavya Shetty', 'Manoj Pillai', 'Pooja Hegde', 'Arvind Joshi', 'Nisha Thomas', 'Gopal Krishna', 'Ritu Agarwal', 'Sanjay Gupta',
            'Divya Rao', 'Harish Gowda', 'Zoya Sheikh', 'Prakash Jain', 'Meera Nambiar', 'Tarun Bose'];
        $areas = [['Koramangala', '560034'], ['HSR Layout', '560102'], ['Whitefield', '560066'], ['Jayanagar', '560041'], ['Hebbal', '560024'], ['Yelahanka', '560064']];
        $customers = collect($names)->map(function ($name, $i) use ($main, $north, $areas, $products, $dealers) {
            [$area, $pin] = $areas[$i % count($areas)];
            $customer = Customer::create([
                'name' => $name, 'phone' => '98450'.str_pad((string) (10000 + $i * 37), 5, '0', STR_PAD_LEFT),
                'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'crm_id' => 'CRM'.(5001 + $i),
                'address' => (10 + $i).', '.($i % 5 + 1).'th Cross, '.$area, 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => $pin,
                'branch_id' => in_array($area, ['Hebbal', 'Yelahanka']) ? $north->id : $main->id,
                'lat' => 12.93 + mt_rand(0, 900) / 10000, 'lng' => 77.58 + mt_rand(0, 900) / 10000,
            ]);
            foreach (range(1, mt_rand(1, 2)) as $n) {
                $purchase = now()->subDays(mt_rand(60, 900));
                $customer->products()->create([
                    'product_id' => $products->random()->id, 'serial_no' => strtoupper(substr(md5($name.$n), 0, 12)),
                    'purchase_date' => $purchase->toDateString(), 'warranty_type' => $purchase->gt(now()->subYear()) ? 'in_warranty' : 'out_of_warranty',
                    'warranty_expiry' => $purchase->copy()->addYear()->toDateString(), 'dealer_id' => $dealers->random()->id,
                ]);
            }

            return $customer->load('products');
        });

        // Jobs across the last 30 days, executed through the real workflow.
        $jobs = app(JobService::class);
        $visits = app(VisitService::class);
        $complaints = ComplaintType::pluck('id')->all();
        $actions = ActionTakenOption::pluck('id', 'name');
        $methods = ['cash', 'cash', 'cash', 'upi', 'upi', 'cheque', 'credit'];
        $coordinator = User::where('email', 'coordinator@demo.test')->first();

        foreach (range(30, 0) as $daysAgo) {
            foreach (range(1, mt_rand(1, 3)) as $n) {
                Carbon::setTestNow($this->today->copy()->subDays($daysAgo)->addHours(8 + $n * 2)->addMinutes(mt_rand(0, 59)));

                $customer = $customers->random();
                $tech = $techs[array_rand($techs)];
                $job = $jobs->create([
                    'customer_id' => $customer->id, 'customer_product_id' => $customer->products->random()->id, 'branch_id' => $customer->branch_id,
                    'complaint_type_id' => $complaints[array_rand($complaints)], 'priority' => ['low', 'medium', 'medium', 'high'][mt_rand(0, 3)],
                    'call_type' => ['crm_call', 'crm_call', 'walk_in', 'referral'][mt_rand(0, 3)],
                    'complaint_details' => ['Unit not cooling properly', 'Water dripping from indoor unit', 'Making loud noise while running', 'Not turning on', 'Periodic maintenance requested'][mt_rand(0, 4)],
                    'scheduled_at' => now()->addHours(mt_rand(2, 30)), 'assigned_technician_id' => $daysAgo < 1 && $n === 1 ? null : $tech->id,
                ], $coordinator);

                if ($daysAgo < 1 || ! $job->assigned_technician_id) {
                    continue; // today's jobs stay open for the demo
                }

                $roll = mt_rand(1, 10);
                if ($roll === 1) {
                    $jobs->cancel($job, 'Customer not reachable', $coordinator);

                    continue;
                }

                Carbon::setTestNow(now()->addHours(mt_rand(3, 20)));
                $visit = $visits->start($job, $tech, ['service_type' => 'on_site', 'lat' => $customer->lat, 'lng' => $customer->lng]);
                $spareCharge = 0;
                if (mt_rand(0, 1)) {
                    $item = $items->random();
                    $qty = $item->unit_of_measure === 'nos' ? 1 : [0.5, 1, 1.5][mt_rand(0, 2)];
                    try {
                        $visits->addInventory($visit, $tech, ['item_id' => $item->id, 'quantity' => $qty]);
                    } catch (\Throwable) {
                        // out of stock at branch – skip
                    }
                }
                Carbon::setTestNow(now()->addMinutes(mt_rand(25, 110)));

                $pending = $roll === 2 || ($daysAgo <= 2 && $roll <= 4);
                $labour = [30000, 45000, 50000, 60000, 75000][mt_rand(0, 4)];
                $method = $methods[array_rand($methods)];
                $visit->refresh();
                $total = $labour + (int) $visit->inventoryUsage()->sum('total_price');
                $visits->complete($visit, $tech, [
                    'status' => $pending ? 'pending' : 'completed',
                    'action_taken_id' => $pending ? $actions['PART ORDERED'] : $actions->random(),
                    'service_summary' => $pending ? 'Part ordered, will revisit' : 'Checked and resolved. Customer satisfied.',
                    'labour_charge' => $labour,
                    'payment_method' => $method,
                    'amount_collected' => $method === 'credit' ? 0 : ($roll === 3 ? intdiv($total, 2) : $total),
                    'payment_reference' => in_array($method, ['upi', 'cheque']) ? strtoupper(substr(md5((string) $job->id), 0, 10)) : null,
                ]);

                if (! $pending && mt_rand(0, 2) > 0) {
                    Review::create(['job_id' => $job->id, 'customer_id' => $customer->id, 'technician_id' => $tech->id,
                        'rating' => [5, 5, 4, 4, 3][mt_rand(0, 4)], 'comment' => ['Very professional', 'Quick and polite service', 'Good work', null][mt_rand(0, 3)]]);
                }
            }
        }

        // Daily cash close: technicians closed older days; accountant verified most of them.
        $cash = app(CashCloseService::class);
        $accountant = User::where('email', 'accounts@demo.test')->first();
        foreach ($techs as $tech) {
            foreach (range(30, 2) as $daysAgo) {
                Carbon::setTestNow($this->today->copy()->subDays($daysAgo)->addHours(23)->addMinutes(30));
                $date = now($tenant->timezone)->toDateString();
                $summary = $cash->summary($tech, $date);
                if ($summary['cash'] + $summary['cheque'] + $summary['digital'] === 0) {
                    continue;
                }
                $short = mt_rand(1, 12) === 1 ? 20000 : 0;
                $close = $cash->submit($tech, $date, $summary['expected_in_hand'] - $short, $short ? 'Gave change to a customer, short by 200' : null, $tech);
                if ($daysAgo > 3) {
                    Carbon::setTestNow(now()->addHours(14));
                    $close = $cash->verify($close, $close->amount_confirmed, $short ? 'Shortfall accepted – to be recovered from salary' : null, $accountant);
                    if ($close->closing_balance <= 0) {
                        continue; // digital-only day: nothing physical to deposit
                    }
                    $cash->deposit($close, ['amount' => $close->closing_balance, 'deposited_to' => 'bank', 'reference_no' => 'DEP'.mt_rand(100000, 999999), 'deposit_date' => now()->toDateString()], $accountant);
                }
            }
        }
        Carbon::setTestNow();

        foreach ($techs as $i => $tech) {
            $tech->forceFill(['punch_status' => $i < 2 ? 'in' : 'out', 'punched_at' => now()->subHours(3)])->save();
        }
    }
}
