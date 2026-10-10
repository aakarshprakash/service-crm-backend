<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which phone app registered a push token. Servon Manager can live in its own Firebase
 * project, so its tokens must be sent with that project's key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('app', 20)->default('technician')->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn('app');
        });
    }
};
