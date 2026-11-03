<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Formatted complaint details from the rich-text editor; complaint_details keeps a plain-text copy for apps and lists. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->text('complaint_details_html')->nullable()->after('complaint_details');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', fn (Blueprint $table) => $table->dropColumn('complaint_details_html'));
    }
};
