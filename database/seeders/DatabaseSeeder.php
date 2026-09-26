<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe base data: subscription plans and the platform Super Admin.
     * Demo data is separate: `php artisan db:seed --class=DemoSeeder`.
     */
    public function run(): void
    {
        $plans = [
            ['code' => 'starter', 'name' => 'Starter', 'price' => 99900, 'billing_cycle' => 'monthly', 'max_users' => 3, 'max_technicians' => 5,
                'features' => ['customer_portal' => true, 'sms' => true, 'whatsapp' => false, 'online_payments' => false, 'advanced_reports' => false]],
            ['code' => 'growth', 'name' => 'Growth', 'price' => 249900, 'billing_cycle' => 'monthly', 'max_users' => 10, 'max_technicians' => 25,
                'features' => ['customer_portal' => true, 'sms' => true, 'whatsapp' => true, 'online_payments' => true, 'advanced_reports' => true]],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'price' => 2499900, 'billing_cycle' => 'yearly', 'max_users' => 100, 'max_technicians' => 500,
                'features' => ['customer_portal' => true, 'sms' => true, 'whatsapp' => true, 'online_payments' => true, 'advanced_reports' => true]],
        ];
        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['code' => $plan['code']], $plan + ['is_active' => true]);
        }

        $email = strtolower((string) env('SUPER_ADMIN_EMAIL', 'superadmin@servicecrm.local'));
        $password = env('SUPER_ADMIN_PASSWORD');
        if (! $password) {
            if (app()->isProduction()) {
                $this->command?->warn('SUPER_ADMIN_PASSWORD is not set – skipping Super Admin creation.');

                return;
            }
            $password = 'Admin@12345';
        }

        if (! User::where('email', $email)->exists()) {
            $admin = new User(['name' => 'Platform Admin', 'email' => $email, 'password' => $password, 'role' => Role::SuperAdmin, 'status' => 'active']);
            $admin->save();
            $this->command?->info("Super Admin created: {$email}");
        }
    }
}
