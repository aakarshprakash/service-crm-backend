<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's unit can be recorded with just its brand and product type (e.g. "LLOYD · INVERTER AC");
 * the exact model is added later. When a model is set, brand and type follow the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->after('brand_id')->constrained('product_categories')->nullOnDelete();
        });
        // Existing units: copy brand and type from their model.
        DB::table('customer_products')->whereNotNull('product_id')->orderBy('id')->each(function ($cp) {
            $p = DB::table('products')->where('id', $cp->product_id)->first(['brand_id', 'category_id']);
            if ($p) {
                DB::table('customer_products')->where('id', $cp->id)->update(['brand_id' => $p->brand_id, 'category_id' => $p->category_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
