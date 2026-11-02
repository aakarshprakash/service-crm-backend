<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A unit's model is now typed text (the technician enters what is on the unit), kept separate from the
 * Products master list. The job gets the Customer Satisfaction Code the technician collects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->string('model_no', 150)->nullable()->after('product_id');
        });

        // Units picked from the master list keep the model name they already show.
        DB::statement('UPDATE customer_products cp JOIN products p ON p.id = cp.product_id SET cp.model_no = p.model_name');

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('customer_satisfaction_code', 50)->nullable()->after('product_category');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropColumn('customer_satisfaction_code');
        });
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropColumn('model_no');
        });
    }
};
