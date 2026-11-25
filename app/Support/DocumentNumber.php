<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\Tenant;

/**
 * Job, invoice and receipt numbers in the format the company chose in Settings:
 * letters + date + a running or random ending, e.g. SC-2611-00042 or AJ2026110248213.
 * Must be called inside a transaction, like Sequence.
 */
class DocumentNumber
{
    /** The date part of a number, as a PHP date format. */
    public const DATE_FORMATS = ['none' => '', 'yy' => 'y', 'yyyy' => 'Y', 'ym' => 'ym', 'yyyymm' => 'Ym', 'yymmdd' => 'ymd', 'yyyymmdd' => 'Ymd'];

    /** Tax invoices may be at most 16 characters long (CGST Rules, rule 46). */
    public const GST_INVOICE_MAX_LENGTH = 16;

    private const COLUMNS = [
        'job' => [ServiceJob::class, 'crm_call_id'],
        // GST tax invoices, and every bill unless non-GST bills have their own series.
        'invoice' => [Invoice::class, 'invoice_number'],
        // Non-GST bills, once Settings gives them their own series (so the GST series has no gaps).
        'non_gst_invoice' => [Invoice::class, 'invoice_number'],
        'receipt' => [Payment::class, 'receipt_number'],
    ];

    /** The kinds of number with a running counter whose next value can be set in Settings. */
    public const TYPES = ['job', 'invoice', 'non_gst_invoice', 'receipt'];

    /**
     * Which series a bill's number comes from. Non-GST bills get their own only when the company is GST
     * registered and has turned it on; otherwise every bill shares the invoice series, as before.
     */
    public static function invoiceSeries(Tenant $tenant, bool $isGst): string
    {
        return ! $isGst && $tenant->gstin && $tenant->setting('non_gst_numbering') ? 'non_gst_invoice' : 'invoice';
    }

    /** The next number for a bill of this kind. */
    public static function invoice(Tenant $tenant, bool $isGst): string
    {
        return self::next($tenant, self::invoiceSeries($tenant, $isGst));
    }

    /** What each running counter gives next, e.g. ['invoice' => 18, ...]. */
    public static function nextValues(Tenant $tenant): array
    {
        $used = \Illuminate\Support\Facades\DB::table('sequences')->where('tenant_id', $tenant->id)->whereIn('name', self::TYPES)->pluck('value', 'name');

        return collect(self::TYPES)->mapWithKeys(fn ($t) => [$t => (int) ($used[$t] ?? 0) + 1])->all();
    }

    /** Make the counter give $next as its next value (a number already used is skipped when issued). */
    public static function setNext(Tenant $tenant, string $type, int $next): void
    {
        \Illuminate\Support\Facades\DB::table('sequences')->updateOrInsert(['tenant_id' => $tenant->id, 'name' => $type], ['value' => max(0, $next - 1)]);
    }

    public static function next(Tenant $tenant, string $type): string
    {
        $rule = $tenant->setting("numbering.{$type}");
        $separator = $rule['separator'] === 'dash' ? '-' : '';
        $date = now($tenant->timezone ?? config('app.timezone'))->format(self::DATE_FORMATS[$rule['date']] ?? '');
        $head = implode($separator, array_filter([$tenant->setting("{$type}_prefix"), $date], fn ($part) => $part !== ''));

        [$model, $column] = self::COLUMNS[$type];
        $taken = fn (string $number) => $model::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where($column, $number)->exists();

        $digits = (int) $rule['digits'];
        for ($attempt = 1; ; $attempt++) {
            $ending = $rule['ending'] === 'random'
                ? self::random($digits + intdiv($attempt, 20)) // a crowded range makes the number longer rather than looping forever
                : str_pad((string) Sequence::next($tenant->id, $type), $digits, '0', STR_PAD_LEFT);
            $number = $head.($head === '' ? '' : $separator).$ending;
            if (! $taken($number)) {
                return $number;
            }
        }
    }

    /** How long a number in this format is once its ending has grown to the chosen digits. */
    public static function length(string $prefix, array $rule): int
    {
        $parts = array_filter([$prefix, now()->format(self::DATE_FORMATS[$rule['date']] ?? '')], fn ($part) => $part !== '');

        return strlen(implode('', $parts)) + (int) $rule['digits'] + ($rule['separator'] === 'dash' ? count($parts) : 0);
    }

    private static function random(int $digits): string
    {
        return (string) random_int(10 ** ($digits - 1), 10 ** $digits - 1);
    }
}
