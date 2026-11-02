<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job card: the product category as plain, searchable text, and what a "part pending" job is
 * waiting for. The status itself is a value of the existing service_jobs.status column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('product_category', 100)->nullable()->after('complaint_details_html');
            $table->string('part_pending_note', 500)->nullable()->after('status');
            $table->timestamp('part_pending_at')->nullable()->after('part_pending_note');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropColumn(['product_category', 'part_pending_note', 'part_pending_at']);
        });
    }
};
