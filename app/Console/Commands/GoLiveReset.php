<?php

namespace App\Console\Commands;

use Database\Seeders\AjClimateCareSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time go-live clean-up: removes all test data, keeping only the Super Admin, the company
 * admin (with their branch) and the company itself with its Settings, then loads the
 * AJ CLIMATE CARE staff and master lists. Without --run it only shows what would happen.
 *
 *   php artisan app:go-live-reset                      (preview)
 *   php artisan app:go-live-reset --run                (do it – take a database backup first)
 */
class GoLiveReset extends Command
{
    protected $signature = 'app:go-live-reset
        {--run : Actually delete and seed (otherwise preview only)}
        {--company=demo : Company slug to keep and seed}
        {--admin-email= : Login of the company admin to keep (default: the first admin created)}
        {--rename-admin= : New name for the kept company admin}
        {--admin-new-email= : New login email for the kept company admin}
        {--no-seed : Only delete, do not load staff and master lists}';

    protected $description = 'Delete all test data except Super Admin + company admin, then seed AJ CLIMATE CARE staff and master lists';

    /** Kept as they are: schema history, the company (with its Settings) and its plan. */
    private const KEEP_TABLES = ['migrations', 'subscription_plans', 'tenants', 'tenant_subscriptions'];

    public function handle(): int
    {
        $tenants = DB::table('tenants')->get(['id', 'slug', 'name']);
        $tenant = $tenants->firstWhere('slug', $this->option('company'));
        if (! $tenant) {
            $this->error('No company with slug "'.$this->option('company').'". Companies: '.$tenants->pluck('slug')->join(', '));

            return self::FAILURE;
        }
        if ($tenants->count() > 1) {
            $this->error('This database has '.$tenants->count().' companies. This reset is only for a single-company installation.');

            return self::FAILURE;
        }

        $superAdmins = DB::table('users')->where('role', 'super_admin')->get(['id', 'name', 'email']);
        $admin = DB::table('users')->where('tenant_id', $tenant->id)->where('role', 'admin')
            ->when($this->option('admin-email'), fn ($q, $email) => $q->where('email', $email))
            ->orderBy('id')->first(['id', 'name', 'email', 'branch_id']);
        if (! $admin) {
            $this->error('No company admin found'.($this->option('admin-email') ? ' with login '.$this->option('admin-email') : '').'.');

            return self::FAILURE;
        }
        $keepUsers = $superAdmins->pluck('id')->push($admin->id)->all();

        $this->info("Company: {$tenant->name} ({$tenant->slug}) – kept with all its Settings");
        foreach ($superAdmins as $u) {
            $this->line("Keep Super Admin:   {$u->name} <{$u->email}>");
        }
        $this->line("Keep company admin: {$admin->name} <{$admin->email}>"
            .($this->option('rename-admin') || $this->option('admin-new-email')
                ? '  ->  '.($this->option('rename-admin') ?: $admin->name).' <'.($this->option('admin-new-email') ?: $admin->email).'>' : ''));
        $this->newLine();

        $db = DB::connection()->getDatabaseName();
        $tables = collect(DB::select("select table_name as t from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name", [$db]))->pluck('t');

        $plan = [];
        $rows = [];
        foreach ($tables as $t) {
            $n = DB::table($t)->count();
            if (in_array($t, self::KEEP_TABLES, true)) {
                $rows[] = ['KEEP', $t, $n, $n];
            } elseif ($t === 'users') {
                $plan[$t] = fn () => DB::table('users')->whereNotIn('id', $keepUsers)->delete();
                $rows[] = ['PARTIAL', $t, $n, count($keepUsers)];
            } elseif ($t === 'branches') {
                $plan[$t] = fn () => DB::table('branches')->where('id', '<>', (int) $admin->branch_id)->delete();
                $rows[] = ['PARTIAL', $t, $n, $admin->branch_id ? 1 : 0];
            } elseif ($n > 0) {
                $plan[$t] = fn () => DB::table($t)->truncate();
                $rows[] = ['CLEAR', $t, $n, 0];
            }
        }
        $this->table(['Action', 'Table', 'Rows now', 'Rows after'], $rows);

        if (! $this->option('run')) {
            $this->warn('Preview only – nothing was changed. Take a database backup, then run again with --run.');

            return self::SUCCESS;
        }
        if (! $this->confirm('This permanently deletes the data marked CLEAR / PARTIAL. Do you have a database backup?')) {
            $this->line('Cancelled.');

            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($plan as $run) {
                $run();
            }
            $rename = array_filter(['name' => $this->option('rename-admin'), 'email' => $this->option('admin-new-email')]);
            if ($rename) {
                DB::table('users')->where('id', $admin->id)->update($rename);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->info('Test data removed.');

        if (! $this->option('no-seed')) {
            // The seeder reads the company from COMPANY_SLUG.
            putenv('COMPANY_SLUG='.$tenant->slug);
            $_ENV['COMPANY_SLUG'] = $_SERVER['COMPANY_SLUG'] = $tenant->slug;
            $this->call('db:seed', ['--class' => AjClimateCareSeeder::class, '--force' => true]);
        }

        $this->call('optimize:clear');
        $this->newLine();
        $this->info('Done. Everyone has been signed out; sign in again. Note the staff temporary password shown above.');

        return self::SUCCESS;
    }
}
