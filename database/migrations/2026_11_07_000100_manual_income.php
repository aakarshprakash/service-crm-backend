<?php

use App\Services\TenantProvisioningService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money received without an invoice: a service charge collected on the spot, a part sold over the counter,
 * scrap, commission. A local business takes much of its income this way, so the books can't rely on invoices alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('income_date');
            $table->foreignId('income_category_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount'); // paise
            $table->string('payment_method', 20); // cash | upi | cheque | bank_transfer
            $table->string('received_from', 150)->nullable(); // the customer or party who paid
            $table->string('phone', 20)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // staff who collected or brought it in
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'income_date']);
        });

        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('income_categories')->insertOrIgnore(array_map(fn ($name) => [
                'tenant_id' => $tenantId, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], TenantProvisioningService::DEFAULT_INCOME_CATEGORIES));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('income_categories');
    }
};
