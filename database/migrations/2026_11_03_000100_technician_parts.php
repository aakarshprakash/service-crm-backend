<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parts the office hands to a technician. They leave the branch's stock when allocated and are
 * tracked per technician until used on a job (technician_stock_moves keeps the job against each use)
 * or returned to a branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            // Branch of the latest allocation: jobs record where the part came from.
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['technician_id', 'item_id']);
        });

        Schema::create('technician_stock_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // allocated | returned | used | usage_undone
            $table->decimal('quantity', 14, 3); // + comes to the technician, - leaves
            $table->decimal('balance_after', 14, 3);
            $table->foreignId('job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->foreignId('job_visit_id')->nullable()->constrained('job_visits')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'technician_id', 'id']);
        });

        Schema::table('job_inventory_usage', function (Blueprint $table) {
            $table->boolean('from_technician_stock')->default(false)->after('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_inventory_usage', function (Blueprint $table) {
            $table->dropColumn('from_technician_stock');
        });
        Schema::dropIfExists('technician_stock_moves');
        Schema::dropIfExists('technician_stock');
    }
};
