<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A technician's cancellation now waits for an admin (status cancel_requested), so the job records who
 * asked and when. Reasons also get room for what the forms already allow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('cancel_reason', 500)->nullable()->change();
            $table->foreignId('cancel_requested_by')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancel_requested_at')->nullable()->after('cancel_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_requested_by');
            $table->dropColumn('cancel_requested_at');
        });
    }
};
