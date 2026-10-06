<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActionTakenOption;
use App\Models\Branch;
use App\Models\ComplaintType;
use App\Models\ExpenseCategory;
use App\Models\LeaveType;
use App\Models\ProductCategory;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FR-1.4: tenant sign-up → plan → trial → provisioned tenant with a default Company
 * Admin and starter master data (§12.3).
 */
class TenantProvisioningService
{
    public const DEFAULT_ACTIONS = [
        'GAS CHARGING DONE', 'SPARE REPLACED', 'SPIN MOTOR REPLACED', 'PCB REPLACED', 'COMPRESSOR REPLACED',
        'GENERAL SERVICE DONE', 'INSTALLATION DONE', 'UNINSTALLATION DONE', 'DEMO GIVEN', 'CUSTOMER EDUCATED',
        'NO FAULT FOUND', 'ESTIMATE GIVEN - CUSTOMER DECLINED', 'PART ORDERED',
    ];

    public const DEFAULT_COMPLAINTS = [
        'Not Cooling', 'Water Leakage', 'Noise / Vibration', 'Not Working / Dead', 'Installation',
        'Uninstallation', 'Periodic Service', 'Demo', 'Error Code',
    ];

    public const DEFAULT_CATEGORIES = ['Air Conditioner', 'Refrigerator', 'Washing Machine', 'Microwave', 'Water Purifier', 'Television'];

    public const DEFAULT_EXPENSE_CATEGORIES = [
        'Fuel & travel', 'Salaries & wages', 'Rent', 'Electricity & utilities', 'Spare parts purchase', 'Tools & equipment',
        'Repairs & maintenance', 'Office supplies', 'Phone & internet', 'Marketing', 'Food & refreshments', 'Other',
    ];

    /** Leave types every company starts with (editable in Master data). */
    public const DEFAULT_LEAVE_TYPES = [
        ['name' => 'Casual leave', 'code' => 'CL', 'annual_quota' => 12, 'is_paid' => true],
        ['name' => 'Sick leave', 'code' => 'SL', 'annual_quota' => 12, 'is_paid' => true],
        ['name' => 'Earned leave', 'code' => 'EL', 'annual_quota' => 15, 'is_paid' => true],
        ['name' => 'Unpaid leave', 'code' => 'LOP', 'annual_quota' => 0, 'is_paid' => false],
    ];

    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plan = SubscriptionPlan::where('is_active', true)->find($data['plan_id'] ?? null)
                ?? SubscriptionPlan::where('is_active', true)->orderBy('price')->first();

            $tenant = Tenant::create([
                'name' => $data['company_name'],
                'slug' => $data['slug'],
                'status' => $data['status'] ?? 'trial',
                'timezone' => $data['timezone'] ?? 'Asia/Kolkata',
                'currency' => $data['currency'] ?? 'INR',
                'plan_id' => $plan?->id,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'trial_ends_at' => now()->addDays((int) config('app.trial_days', 14)),
                'settings' => Tenant::DEFAULT_SETTINGS,
            ]);

            if ($plan) {
                TenantSubscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => $tenant->status === 'trial' ? 'trial' : 'active',
                    'starts_at' => now(),
                    'ends_at' => $tenant->trial_ends_at,
                ]);
            }

            $admin = app(TenantContext::class)->runAs($tenant->id, function () use ($tenant, $data) {
                $branch = Branch::create(['name' => 'Main Branch', 'code' => 'MAIN']);
                foreach (self::DEFAULT_ACTIONS as $name) {
                    ActionTakenOption::create(['name' => $name]);
                }
                foreach (self::DEFAULT_COMPLAINTS as $name) {
                    ComplaintType::create(['name' => $name]);
                }
                foreach (self::DEFAULT_CATEGORIES as $name) {
                    ProductCategory::create(['name' => $name]);
                }
                foreach (self::DEFAULT_EXPENSE_CATEGORIES as $name) {
                    ExpenseCategory::create(['name' => $name]);
                }
                foreach (self::DEFAULT_LEAVE_TYPES as $type) {
                    LeaveType::create($type);
                }

                $admin = new User([
                    'name' => $data['name'],
                    'email' => strtolower($data['email']),
                    'phone' => $data['phone'] ?? null,
                    'password' => $data['password'],
                    'role' => Role::Admin,
                    'branch_id' => $branch->id,
                    'status' => 'active',
                ]);
                $admin->tenant_id = $tenant->id;
                $admin->save();

                return $admin;
            });

            return ['tenant' => $tenant, 'admin' => $admin];
        });
    }
}
