<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cash and bank books add up every receipt in a period, whoever collected it: an index on the
 * payment date lets the database do that without reading the whole table. The same for a technician's
 * visits by start time, which the Trips screen reads a day or a month at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'paid_at'], 'payments_tenant_status_paid_at_index');
        });
        Schema::table('job_visits', function (Blueprint $table) {
            $table->index(['tenant_id', 'technician_id', 'start_time'], 'job_visits_tenant_technician_start_index');
        });
    }

    public function down(): void
    {
        Schema::table('job_visits', function (Blueprint $table) {
            $table->dropIndex('job_visits_tenant_technician_start_index');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_tenant_status_paid_at_index');
        });
    }
};
