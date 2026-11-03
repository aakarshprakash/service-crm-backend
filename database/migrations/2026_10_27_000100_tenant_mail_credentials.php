<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A company's own outgoing-email (SMTP) account. Null = use the platform mailer from .env. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('mail_credentials')->nullable()->after('gateway_credentials'); // encrypted JSON
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('mail_credentials');
        });
    }
};
