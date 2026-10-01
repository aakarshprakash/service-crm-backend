<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('code', 20)->nullable();
            $table->string('city', 100)->nullable();
            // Comma-separated PIN codes covered, used to suggest the location for a customer.
            $table->text('pincodes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('service_location_user', function (Blueprint $table) {
            $table->foreignId('service_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['service_location_id', 'user_id']);
        });

        Schema::table('service_jobs', function (Blueprint $table) {
            $table->foreignId('service_location_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropConstrainedForeignId('service_location_id'));
        Schema::dropIfExists('service_location_user');
        Schema::dropIfExists('service_locations');
    }
};
