<?php

use App\Services\TenantProvisioningService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1: UPI collection accounts, cash / bank books with fund transfers, geo-fenced
 * attendance and the HR module (leave, employee profiles, payroll).
 */
return new class extends Migration
{
    public function up(): void
    {
        // UPI IDs the company collects into; used to print / show "scan to pay" QR codes.
        Schema::create('upi_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('vpa', 100);
            $table->string('payee_name', 100);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        // Contra entries between the cash book and the bank book (cash deposited, cash withdrawn).
        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('transfer_date');
            $table->string('direction', 20); // cash_to_bank | bank_to_cash
            $table->bigInteger('amount');
            $table->string('reference_no', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'transfer_date']);
        });

        // Geofence: a branch's location and the radius staff must be within to punch.
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('phone');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->unsignedInteger('geofence_radius')->nullable()->after('lng'); // metres
        });

        Schema::table('punch_logs', function (Blueprint $table) {
            $table->decimal('accuracy', 8, 1)->nullable()->after('lng');
            $table->foreignId('branch_id')->nullable()->after('accuracy')->constrained()->nullOnDelete();
            $table->unsignedInteger('distance_m')->nullable()->after('branch_id');
            $table->boolean('within_fence')->nullable()->after('distance_m');
            $table->string('source', 10)->nullable()->after('within_fence'); // web | mobile
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'date']);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 10)->nullable();
            $table->decimal('annual_quota', 5, 1)->default(0); // 0 = no limit tracked
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 5, 1);
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'from_date']);
        });

        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_code', 30)->nullable();
            $table->string('designation', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->date('date_of_joining')->nullable();
            $table->date('date_of_leaving')->nullable();
            $table->bigInteger('monthly_salary')->default(0); // gross, minor units
            $table->json('components')->nullable(); // [{name, type: earning|deduction, amount}]
            $table->json('weekly_offs')->nullable(); // ISO weekdays, 7 = Sunday
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account', 40)->nullable();
            $table->string('ifsc', 20)->nullable();
            $table->string('pan', 20)->nullable();
            $table->string('uan', 30)->nullable();
            $table->timestamps();
        });

        // Manual corrections to attendance (forgot to punch, on-site training, half day…).
        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 20); // present | half_day | absent
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // YYYY-MM
            $table->string('status', 20)->default('draft'); // draft | finalized | paid
            $table->bigInteger('total_gross')->default(0);
            $table->bigInteger('total_deductions')->default(0);
            $table->bigInteger('total_net')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('working_days', 5, 1);
            $table->decimal('present_days', 5, 1);
            $table->decimal('paid_leave_days', 5, 1)->default(0);
            $table->decimal('unpaid_leave_days', 5, 1)->default(0);
            $table->decimal('absent_days', 5, 1)->default(0);
            $table->decimal('holidays', 5, 1)->default(0);
            $table->decimal('weekly_offs', 5, 1)->default(0);
            $table->decimal('lop_days', 5, 1)->default(0);
            $table->bigInteger('gross');
            $table->json('earnings')->nullable();
            $table->json('deductions')->nullable();
            $table->bigInteger('lop_amount')->default(0);
            $table->bigInteger('bonus')->default(0);
            $table->bigInteger('other_deduction')->default(0);
            $table->bigInteger('net_pay');
            $table->string('note', 300)->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'user_id']);
        });

        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('leave_types')->insertOrIgnore(array_map(fn ($t) => $t + [
                'tenant_id' => $tenantId, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], TenantProvisioningService::DEFAULT_LEAVE_TYPES));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('attendance_adjustments');
        Schema::dropIfExists('employee_profiles');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('holidays');
        Schema::table('punch_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['accuracy', 'distance_m', 'within_fence', 'source']);
        });
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['lat', 'lng', 'geofence_radius']));
        Schema::dropIfExists('fund_transfers');
        Schema::dropIfExists('upi_accounts');
    }
};
