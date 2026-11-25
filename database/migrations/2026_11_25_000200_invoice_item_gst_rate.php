<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A counter bill line's own GST rate, chosen on the bill as on an estimate line.
 * Null: the part's own rate, else the rate in Settings → GST (as every bill before this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('gst_rate', 5, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('gst_rate');
        });
    }
};
