<?php

use App\Services\TenantProvisioningService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Walk-in (counter) bills are invoices without a job, carrying their own line items.
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('job_id')->nullable()->change();
            $table->string('source', 20)->default('job')->after('job_id'); // job | walk_in
            $table->bigInteger('discount_amount')->default(0)->after('total_spare_charge');
            $table->string('notes', 500)->nullable()->after('is_credit');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'source', 'generated_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // service | part
            $table->foreignId('item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 14, 3)->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('total');
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('expense_date');
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('payment_method', 20); // cash | upi | cheque | bank_transfer
            $table->string('paid_to', 150)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // staff member the expense is for
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'expense_date']);
        });

        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('expense_categories')->insertOrIgnore(array_map(fn ($name) => [
                'tenant_id' => $tenantId, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], TenantProvisioningService::DEFAULT_EXPENSE_CATEGORIES));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('invoice_items');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source', 'generated_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['source', 'discount_amount', 'notes']);
        });
    }
};
