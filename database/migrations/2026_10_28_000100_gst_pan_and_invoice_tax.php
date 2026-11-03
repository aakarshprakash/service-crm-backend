<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST tax invoices. Prices are GST-inclusive, so an invoice's total never changes:
 * the tax is a breakup of it (taxable value + CGST/SGST or IGST), snapshotted on the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('pan', 10)->nullable()->after('gstin');
            $table->string('state', 60)->nullable()->after('address'); // place of business, for CGST+SGST vs IGST
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('gstin', 15)->nullable()->after('email'); // B2B customers
        });
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('hsn_code', 8)->nullable()->after('category');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('hsn_code'); // null = company default part rate
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_gst')->default(false)->after('total_amount');
            $table->bigInteger('taxable_amount')->default(0)->after('is_gst');
            $table->bigInteger('cgst_amount')->default(0)->after('taxable_amount');
            $table->bigInteger('sgst_amount')->default(0)->after('cgst_amount');
            $table->bigInteger('igst_amount')->default(0)->after('sgst_amount');
            $table->string('place_of_supply', 60)->nullable()->after('igst_amount');
            $table->string('customer_gstin', 15)->nullable()->after('place_of_supply');
            $table->json('gst_breakup')->nullable()->after('customer_gstin');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['is_gst', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'place_of_supply', 'customer_gstin', 'gst_breakup']);
        });
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn(['hsn_code', 'gst_rate']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('gstin'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['pan', 'state']));
    }
};
