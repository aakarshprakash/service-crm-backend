<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account security for production: temporary passwords that must be changed at the next
 * sign-in, and enough about each session (which app, IP, browser / phone) for admins to
 * see where someone is signed in and sign them out remotely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('app', 20)->nullable()->after('name');
            $table->string('ip_address', 45)->nullable()->after('app');
            $table->string('user_agent', 255)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['app', 'ip_address', 'user_agent']);
        });
    }
};
