<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company signature and stamp/seal image, printed on the estimate (and optionally the invoice) PDF.
 * Each estimate keeps whether it shows them; the default comes from Settings → signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('logo_path');
            $table->string('stamp_path')->nullable()->after('signature_path');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->boolean('show_signature')->default(true)->after('prices_include_gst');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', fn (Blueprint $table) => $table->dropColumn('show_signature'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['signature_path', 'stamp_path']));
    }
};
