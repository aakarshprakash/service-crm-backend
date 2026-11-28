<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries (leads): someone asked about a repair or about buying something. The office follows it up
 * until it becomes a job or a quotation, or is lost. An enquiry never touches stock or the accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('enquiry_number', 40);
            $table->string('type', 20)->default('service'); // service | sales
            $table->string('status', 20)->default('new');   // new | follow_up | converted | lost
            $table->string('source', 30)->default('phone');
            // Who asked. Linked to a customer once one exists with this phone number.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('pincode', 12)->nullable();
            // What they asked about.
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product', 150)->nullable();
            $table->text('details')->nullable();
            $table->bigInteger('expected_value')->nullable(); // paise; what a sale could be worth
            $table->dateTime('follow_up_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // What it became.
            $table->foreignId('job_id')->nullable()->constrained('service_jobs')->nullOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lost_reason', 300)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'enquiry_number']);
            $table->index(['tenant_id', 'status', 'follow_up_at']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'created_at']);
        });

        // The follow-up trail: calls made, notes, and each change of status.
        Schema::create('enquiry_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('note'); // note | status
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_notes');
        Schema::dropIfExists('enquiries');
    }
};
