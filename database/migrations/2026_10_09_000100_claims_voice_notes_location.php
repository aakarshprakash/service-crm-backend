<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technician expense claims (paid from cash in hand or own money), voice notes on
 * visits, and customer location sharing (link sent to the customer, or pasted by the
 * office, or taken on site by the technician).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories');
            $table->date('claim_date');
            $table->bigInteger('amount');
            $table->string('paid_from', 20); // cash_in_hand | own_money
            $table->string('description', 500)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('cash_close_id')->nullable()->constrained('cash_closes')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'cash_close_id']);
        });

        Schema::table('cash_closes', function (Blueprint $table) {
            // Spent from collected cash on approved / pending expense claims.
            $table->bigInteger('total_expenses')->default(0)->after('total_digital_collected');
        });

        Schema::create('job_voice_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('job_visit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['job_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('location_updated_at')->nullable()->after('lng');
            $table->string('location_source', 20)->nullable()->after('location_updated_at'); // customer | office | technician
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('location_token', 48)->nullable()->unique()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', fn (Blueprint $t) => $t->dropColumn('location_token'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['location_updated_at', 'location_source']));
        Schema::dropIfExists('job_voice_notes');
        Schema::table('cash_closes', fn (Blueprint $t) => $t->dropColumn('total_expenses'));
        Schema::dropIfExists('expense_claims');
    }
};
