<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand spares: parts the brand (Lloyd) bills to the service centre, one row per billed line, and
 * what happened to each afterwards. Warranty parts must go back to the brand (inspection, RMA or
 * challan, courier LR); out-of-warranty and stock parts need nothing returned.
 *
 * A separate tracker on purpose: it never moves Inventory stock or the accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_spares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            // From the brand's billing (SAP export).
            $table->string('billing_doc', 30);
            $table->date('billing_date')->nullable();
            $table->string('material_code', 40);
            $table->string('description', 200)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->bigInteger('value')->default(0); // paise
            $table->string('delivery_no', 30)->nullable();
            $table->string('po_number', 40)->nullable();
            // The customer's call the part was used on; it finds the job booked with the same External CRM call ID.
            $table->string('ticket_no', 40)->nullable();
            $table->string('customer_name', 150)->nullable();
            // warranty | out_of_warranty | stock; null until someone sorts it.
            $table->string('coverage', 20)->nullable();
            $table->string('return_type', 10)->default('defective'); // defective | fresh (unused part sent back)
            $table->string('inspection_no', 120)->nullable();
            $table->string('rma_no', 120)->nullable(); // RMA number or delivery challan
            $table->string('lr_number', 60)->nullable();
            $table->date('lr_date')->nullable();
            $table->string('courier', 60)->nullable();
            // A warranty part the brand charged to us as out of warranty (not returned in time, etc.).
            $table->boolean('ow_billed')->default(false);
            $table->date('ow_billed_on')->nullable();
            $table->string('ow_bill_no', 30)->nullable();
            // On the brand's latest "pending returns" list.
            $table->boolean('company_pending')->default(false);
            $table->date('company_checked_on')->nullable();
            // Worked out from the fields above on every save: unsorted | inspect | ready | sent | closed.
            $table->string('stage', 12)->default('unsorted');
            $table->text('remarks')->nullable();
            // Identifies the billed line, so the same Excel can be uploaded twice without duplicates.
            $table->string('import_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'import_key']);
            $table->index(['tenant_id', 'stage', 'billing_date']);
            $table->index(['tenant_id', 'billing_doc', 'material_code']);
            $table->index(['tenant_id', 'ticket_no']);
            $table->index(['tenant_id', 'company_pending']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_spares');
    }
};
