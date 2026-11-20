<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST details on estimates. Whether the rates typed are before GST (GST added on top, as on a
 * supply quotation) or include it comes from Settings → GST and is kept on each estimate.
 * Each line gets its own GST rate (from Settings unless changed), a unit (Nos, Mtr, Set) and a
 * description under the item name for a full equipment specification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            // Existing estimates were made with GST-inclusive prices.
            $table->boolean('prices_include_gst')->default(true)->after('is_gst');
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->text('description')->change();
            $table->text('details')->nullable()->after('description');
            $table->string('unit', 20)->nullable()->after('quantity');
            // Null: the company's rate from Settings (services) or the part's own / default rate.
            $table->decimal('gst_rate', 5, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn(['details', 'unit', 'gst_rate']);
            $table->string('description', 300)->change();
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('prices_include_gst');
        });
    }
};
