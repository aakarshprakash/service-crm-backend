<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office's own end-of-day cash count. The accountant counts the cash drawer, the system works out what it
 * should hold from the money that passed through the office since the last count, and the difference is kept.
 * Closing a day locks the office's entries on or before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_day_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('close_date');
            $table->date('period_from'); // first day this count covers (the day after the previous close)
            // All amounts in paise.
            $table->bigInteger('opening_cash');      // counted at the previous close, or entered on the first close
            $table->bigInteger('counter_receipts');  // cash payments taken at the office
            $table->bigInteger('other_income');      // cash income without an invoice
            $table->bigInteger('from_technicians');  // cash technicians handed over at the office
            $table->bigInteger('from_bank');         // cash withdrawn from the bank
            $table->bigInteger('cash_expenses');     // expenses paid from the drawer
            $table->bigInteger('to_bank');           // cash deposited in the bank
            $table->bigInteger('expected_cash');
            $table->bigInteger('counted_cash');
            $table->bigInteger('difference');        // counted − expected: negative is short, positive is extra
            $table->string('remarks', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'close_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_day_closes');
    }
};
