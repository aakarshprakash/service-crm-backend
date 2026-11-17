<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer's feedback on a job was taken: their Customer Satisfaction Code, a rating on the
 * portal, or the office marking it. A completed job stays in the technician's app until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->timestamp('feedback_taken_at')->nullable()->after('customer_satisfaction_code');
            $table->foreignId('feedback_taken_by')->nullable()->after('feedback_taken_at');
            $table->string('feedback_note', 300)->nullable()->after('feedback_taken_by');
        });

        // Jobs already completed are not sent back to the technicians' lists.
        DB::statement("UPDATE service_jobs SET feedback_taken_at = COALESCE(completed_at, updated_at, created_at) WHERE status = 'completed'");
        DB::statement("UPDATE service_jobs SET feedback_taken_at = COALESCE(updated_at, created_at) WHERE feedback_taken_at IS NULL AND customer_satisfaction_code IS NOT NULL AND customer_satisfaction_code <> ''");
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropColumn(['feedback_taken_at', 'feedback_taken_by', 'feedback_note']);
        });
    }
};
