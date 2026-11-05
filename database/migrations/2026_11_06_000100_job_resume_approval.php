<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job a technician left pending or part pending stays with them, but they need the office's go-ahead
 * before the next visit. The request and the approval are kept on the job; starting a visit uses the approval up.
 * Auto punch-outs and auto-closed visits are marked, so the office can tell them from the real thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->timestamp('resume_requested_at')->nullable()->after('cancel_requested_at');
            $table->string('resume_request_note', 500)->nullable()->after('resume_requested_at');
            $table->timestamp('resume_approved_at')->nullable()->after('resume_request_note');
            $table->foreignId('resume_approved_by')->nullable()->after('resume_approved_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('job_visits', function (Blueprint $table) {
            $table->boolean('auto_closed')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('job_visits', function (Blueprint $table) {
            $table->dropColumn('auto_closed');
        });
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resume_approved_by');
            $table->dropColumn(['resume_requested_at', 'resume_request_note', 'resume_approved_at']);
        });
    }
};
