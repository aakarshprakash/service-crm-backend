<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core CRM schema. Every tenant-owned table carries tenant_id (indexed FK) and is
 * filtered by the TenantScope global scope. Money columns are integers in the
 * smallest currency unit (paise); stock quantities are DECIMAL(14,3).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gap-free per-tenant counters (call IDs, invoice / receipt numbers), row-locked on use.
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->unsignedBigInteger('value')->default(0);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('punch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 5); // in | out
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('purpose', 30);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'purpose', 'tenant_id']);
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('platform', 20)->default('android');
            $table->timestamps();
            $table->unique(['user_id', 'token']);
        });

        // ---- Master data -------------------------------------------------
        foreach (['brands', 'product_categories', 'complaint_types', 'action_taken_options'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['tenant_id', 'name']);
            });
        }

        Schema::create('complaint_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('complaint_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'complaint_type_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('model_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'model_name']);
        });

        Schema::create('dealers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event', 50);
            $table->string('channel', 20); // sms | whatsapp | push | email
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'event', 'channel']);
        });

        // ---- Customers ---------------------------------------------------
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('crm_id', 50)->nullable();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('alt_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'crm_id']);
        });

        Schema::create('customer_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_no', 100)->nullable();
            $table->string('outdoor_serial_no', 100)->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('warranty_type', 30)->nullable(); // in_warranty | extended | amc | out_of_warranty
            $table->date('warranty_expiry')->nullable();
            $table->foreignId('dealer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'serial_no']);
        });

        // ---- Jobs --------------------------------------------------------
        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('crm_call_id', 50);
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('complaint_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('complaint_summary_id')->nullable()->constrained()->nullOnDelete();
            $table->text('complaint_details')->nullable();
            $table->string('priority', 10)->default('medium'); // low | medium | high
            $table->string('call_type', 20)->default('crm_call'); // crm_call | walk_in | referral | portal
            $table->string('status', 20)->default('open'); // open | in_progress | pending | completed | cancelled
            $table->string('service_type', 20)->nullable(); // on_site | tele_call | office
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('assigned_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'crm_call_id']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'assigned_technician_id', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('job_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->string('status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->index(['job_id', 'changed_at']);
        });

        Schema::create('cash_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->constrained('users');
            $table->date('close_date');
            $table->bigInteger('opening_balance')->default(0);        // undeposited cash carried forward
            $table->bigInteger('total_cash_collected')->default(0);
            $table->bigInteger('total_cheque_collected')->default(0);
            $table->bigInteger('total_digital_collected')->default(0); // UPI / bank transfer (info only)
            $table->bigInteger('expected_in_hand')->default(0);       // opening + cash + cheque
            $table->bigInteger('amount_confirmed')->default(0);       // declared by technician
            $table->string('technician_remarks', 500)->nullable();
            $table->bigInteger('amount_verified')->nullable();        // counted by accountant
            $table->bigInteger('discrepancy_amount')->default(0);     // verified - expected
            $table->string('discrepancy_remarks', 500)->nullable();
            $table->bigInteger('total_deposited')->default(0);
            $table->bigInteger('closing_balance')->default(0);        // carried forward to next day
            $table->string('status', 20)->default('submitted');       // submitted | verified | closed
            $table->boolean('force_closed')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['technician_id', 'close_date']);
            $table->index(['tenant_id', 'status', 'close_date']);
        });

        Schema::create('job_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users');
            $table->date('visit_date');
            $table->string('service_type', 20);
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 20)->default('in_progress'); // in_progress | pending | completed | cancelled
            $table->foreignId('action_taken_id')->nullable()->constrained('action_taken_options')->nullOnDelete();
            $table->text('service_summary')->nullable();
            $table->foreignId('assisted_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('labour_charge')->default(0);
            $table->bigInteger('spare_charge')->default(0);
            $table->bigInteger('total_charge')->default(0);
            $table->string('payment_method', 20)->nullable(); // cash | upi | cheque | bank_transfer | credit | online
            $table->bigInteger('amount_collected')->default(0);
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'technician_id', 'visit_date']);
            $table->index(['job_id', 'start_time']);
        });

        Schema::create('job_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('job_visit_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20); // bill | serial | complaint_part | new_part | other
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['job_id', 'type']);
        });

        // ---- Inventory ---------------------------------------------------
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('type', 20); // spare | consumable
            $table->string('category', 100)->nullable();
            $table->string('unit_of_measure', 10)->default('nos'); // nos | ml | ltr | gram | kg | metre
            $table->bigInteger('unit_price')->default(0); // selling price per unit
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'name']);
        });

        Schema::create('inventory_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity_available', 14, 3)->default(0);
            $table->bigInteger('avg_unit_cost')->default(0); // weighted average cost for valuation
            $table->timestamp('low_stock_alerted_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'item_id']);
            $table->index(['tenant_id', 'item_id']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->string('type', 20); // stock_in | stock_out | transfer_in | transfer_out | adjustment
            $table->decimal('quantity', 14, 3); // signed: positive adds stock
            $table->decimal('balance_after', 14, 3);
            $table->bigInteger('unit_cost')->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_ref', 100)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'item_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('job_inventory_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('job_visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->foreignId('branch_id')->constrained();
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price');
            $table->bigInteger('total_price');
            $table->timestamps();
            $table->index(['tenant_id', 'item_id', 'created_at']);
        });

        // ---- Billing -----------------------------------------------------
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 40);
            $table->bigInteger('total_service_charge')->default(0);
            $table->bigInteger('total_spare_charge')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('balance_amount')->default(0);
            $table->string('payment_status', 20)->default('unpaid'); // unpaid | partial | paid
            $table->boolean('is_credit')->default(false);
            $table->string('pay_token', 64)->unique();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'invoice_number']);
            $table->unique('job_id');
            $table->index(['tenant_id', 'payment_status', 'generated_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_visit_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('method', 20); // cash | upi | cheque | bank_transfer | online
            $table->string('receipt_number', 40)->nullable();
            $table->string('reference_no', 100)->nullable(); // UPI ref / cheque no / bank UTR
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_order_id', 100)->nullable()->index();
            $table->string('gateway_txn_id', 100)->nullable();
            $table->string('status', 20)->default('success'); // pending | success | failed
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_close_id')->nullable()->constrained()->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'collected_by', 'paid_at']);
            $table->index(['tenant_id', 'method', 'status']);
        });

        Schema::create('cash_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_close_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users');
            $table->bigInteger('amount');
            $table->string('deposited_to', 20); // office | bank
            $table->string('reference_no', 100)->nullable();
            $table->date('deposit_date');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'technician_id', 'deposit_date']);
        });

        // ---- Communication, feedback, audit -----------------------------
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 50);
            $table->string('channel', 20); // in_app | push | sms | whatsapp | email
            $table->string('recipient')->nullable();
            $table->string('title')->nullable();
            $table->text('message');
            $table->json('data')->nullable();
            $table->string('status', 20)->default('queued'); // queued | sent | failed
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'channel', 'created_at']);
            $table->index(['user_id', 'channel', 'read_at']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique('job_id');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('model', 80)->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['model', 'model_id']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report', 50);
            $table->string('format', 5);
            $table->json('filters')->nullable();
            $table->string('status', 20)->default('queued'); // queued | processing | ready | failed
            $table->string('file_path')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['customer_id']));
        foreach ([
            'report_exports', 'audit_logs', 'reviews', 'notification_logs', 'cash_deposits', 'payments',
            'invoices', 'job_inventory_usage', 'inventory_transactions', 'inventory_stock', 'inventory_items',
            'suppliers', 'job_images', 'job_visits', 'cash_closes', 'job_status_history', 'service_jobs',
            'customer_products', 'customers', 'notification_templates', 'dealers', 'products',
            'complaint_summaries', 'action_taken_options', 'complaint_types', 'product_categories', 'brands',
            'device_tokens', 'otp_codes', 'punch_logs', 'sequences',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
