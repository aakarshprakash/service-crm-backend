<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MySQL / MariaDB servers running with explicit_defaults_for_timestamp=OFF (XAMPP's
 * default) silently add "ON UPDATE CURRENT_TIMESTAMP" to the first NOT NULL TIMESTAMP
 * column of a table. That reset the visit start time (timer, duration), the invoice date
 * and the OTP expiry on every update. Redefine those columns without it.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'job_visits' => 'start_time',
        'invoices' => 'generated_at',
        'otp_codes' => 'expires_at',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(): void
    {
        // Nothing to restore: the implicit ON UPDATE behaviour was never intended.
    }
};
