<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the filters the dashboard, lists, books and reports use most, so a company with years of
 * records doesn't read whole tables:
 *  - jobs by the day they were logged or completed (counts, reports, "this month"), without a status filter;
 *  - bills by bill date whatever their status (sales in the ledger, GST register) and by what is still owed;
 *  - visits by day across all technicians (technician job report, trips);
 *  - ratings by date (dashboard average).
 */
return new class extends Migration
{
    private const INDEXES = [
        'service_jobs' => [
            'service_jobs_tenant_created_index' => ['tenant_id', 'created_at'],
            'service_jobs_tenant_completed_index' => ['tenant_id', 'completed_at'],
        ],
        'invoices' => [
            'invoices_tenant_generated_index' => ['tenant_id', 'generated_at'],
            'invoices_tenant_balance_index' => ['tenant_id', 'balance_amount'],
        ],
        'job_visits' => [
            'job_visits_tenant_start_index' => ['tenant_id', 'start_time'],
        ],
        'reviews' => [
            'reviews_tenant_created_index' => ['tenant_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($table, $indexes) {
                foreach ($indexes as $name => $columns) {
                    if (! Schema::hasIndex($table, $name)) {
                        $t->index($columns, $name);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }
                // MySQL lets a foreign key use a new index and drops its own: give the key its index back first.
                $others = collect(Schema::getIndexes($table))->reject(fn ($i) => $i['name'] === $name);
                if (! $others->contains(fn ($i) => ($i['columns'][0] ?? null) === $columns[0])) {
                    Schema::table($table, fn (Blueprint $t) => $t->index([$columns[0]], "{$table}_{$columns[0]}_fk_index"));
                }
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
