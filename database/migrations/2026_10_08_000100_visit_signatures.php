<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer sign-off on a visit: the signature itself is a job_images row of type
 * "signature"; the visit records who signed and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_visits', function (Blueprint $table) {
            $table->string('signer_name', 100)->nullable()->after('amount_collected');
            $table->timestamp('signed_at')->nullable()->after('signer_name');
        });
    }

    public function down(): void
    {
        Schema::table('job_visits', function (Blueprint $table) {
            $table->dropColumn(['signer_name', 'signed_at']);
        });
    }
};
