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
        'invoice' => [Invoice::class, 'invoice_number'],
        'receipt' => [Payment::class, 'receipt_number'],
    ];

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
