<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SAPRE spare parts: parts the brand (Lloyd) bills to the service centre, one row per billed line,
 * and what happened to each afterwards.
 *
 *   Warranty: inspection → RMA / challan → courier (LR) → Lloyd receives it (→ replacement).
 *   Out of warranty: marked OW (orange) → Lloyd bills it (light blue) → paid (grey).
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
            $table->string('delivery_no', 30)->nullable(); // the brand's delivery of the part to us
            $table->string('po_number', 40)->nullable();
            $table->date('received_on')->nullable(); // the part reached us
            // The customer's call the part was used on; it finds the job booked with the same External CRM call ID.
            $table->string('ticket_no', 40)->nullable();
            $table->string('customer_name', 150)->nullable();
            // warranty | out_of_warranty | stock; null until someone sorts it.
            $table->string('coverage', 20)->nullable();

            // Warranty return.
            $table->string('return_type', 10)->default('defective'); // defective | fresh (unused part sent back)
            $table->string('inspection_no', 120)->nullable();
            $table->date('inspection_on')->nullable();
            $table->string('rma_no', 120)->nullable(); // RMA number or delivery challan
            $table->date('rma_on')->nullable();
            $table->string('lr_number', 60)->nullable();
            $table->date('lr_date')->nullable();
            $table->string('courier', 60)->nullable();
            $table->date('return_received_on')->nullable(); // Lloyd confirmed it received the part
            $table->string('replacement_doc', 30)->nullable(); // replacement part issued after inspection
            $table->string('replacement_material', 40)->nullable();
            $table->date('replacement_on')->nullable();

            // Out-of-warranty billing. ow_billed: a warranty part the brand charged to us instead.
            $table->boolean('ow_billed')->default(false);
            $table->string('ow_stage', 10)->nullable(); // marked (orange) | billed (light blue) | paid (grey)
            $table->date('ow_billed_on')->nullable();
            $table->string('ow_bill_no', 30)->nullable();
            $table->date('ow_paid_on')->nullable();
            $table->string('ow_payment_ref', 60)->nullable();

            // On the brand's latest "pending returns" list.
            $table->boolean('company_pending')->default(false);
            $table->date('company_checked_on')->nullable();

            // Worked out from the fields above on every save; see BrandSpare::statusFor().
            $table->string('status', 24)->default('unsorted');
            $table->text('remarks')->nullable();
            // Identifies the billed line, so the same Excel can be uploaded again to update it.
            $table->string('import_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'import_key']);
            $table->index(['tenant_id', 'status', 'billing_date']);
            $table->index(['tenant_id', 'billing_doc', 'material_code']);
            $table->index(['tenant_id', 'ticket_no']);
            $table->index(['tenant_id', 'company_pending']);
        });

        // Every Excel upload, so the office can see who uploaded what and which rows were refused.
        Schema::create('spare_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10); // bill | pending
            $table->string('file_name', 200);
            $table->unsignedInteger('lines')->default(0);
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->json('problems')->nullable(); // [{sheet, row, reason}], the first 200
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spare_imports');
        Schema::dropIfExists('brand_spares');
    }
};
