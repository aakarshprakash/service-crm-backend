<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a bill's prices include GST (GST inclusive) or are before GST, with GST added on top
 * (GST exclusive). Counter bills take it from Settings → GST; job bills always include GST.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Every bill so far was made with GST-inclusive prices.
            $table->boolean('prices_include_gst')->default(true)->after('is_gst');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('prices_include_gst');
        });
    }
};
