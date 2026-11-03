<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working hours. A shift is set per employee, or a company default covers everyone else. Each punch keeps
 * a copy of the shift it was made under, so editing or changing a shift doesn't rewrite past attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('start_time', 5); // HH:MM in the company's timezone
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('grace_minutes')->default(10);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('weekly_offs')->constrained('shifts')->nullOnDelete();
        });

        Schema::table('punch_logs', function (Blueprint $table) {
            $table->string('shift_name', 60)->nullable()->after('source');
            $table->string('shift_start', 5)->nullable()->after('shift_name');
            $table->string('shift_end', 5)->nullable()->after('shift_start');
            $table->unsignedSmallInteger('shift_grace')->nullable()->after('shift_end');
        });
    }

    public function down(): void
    {
        Schema::table('punch_logs', function (Blueprint $table) {
            $table->dropColumn(['shift_name', 'shift_start', 'shift_end', 'shift_grace']);
        });
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });
        Schema::dropIfExists('shifts');
    }
};
