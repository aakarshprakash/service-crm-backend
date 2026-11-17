<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates (quotations): what a repair or installation will cost, sent to the customer before the work.
 * An estimate never touches stock or the accounts; the invoice still comes from the job's visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('estimate_number', 40);
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft | sent | approved | rejected
            $table->date('estimate_date');
            $table->date('valid_until')->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            // Money in paise. Prices include GST, as on invoices.
            $table->bigInteger('total_service_charge')->default(0);
            $table->bigInteger('total_spare_charge')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->boolean('is_gst')->default(false);
            $table->bigInteger('taxable_amount')->default(0);
            $table->bigInteger('cgst_amount')->default(0);
            $table->bigInteger('sgst_amount')->default(0);
            $table->bigInteger('igst_amount')->default(0);
            $table->string('place_of_supply', 100)->nullable();
            $table->string('customer_gstin', 20)->nullable();
            $table->json('gst_breakup')->nullable();
            // Lets the customer open the PDF from a link, without signing in.
            $table->string('share_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'estimate_number']);
            $table->index(['tenant_id', 'status', 'estimate_date']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // service | part
            $table->foreignId('item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('description', 300);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->bigInteger('unit_price')->default(0);
            $table->bigInteger('total')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
    }
};
