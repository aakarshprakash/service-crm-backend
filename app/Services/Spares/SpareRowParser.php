<?php

namespace App\Services\Spares;

use DateTimeInterface;

/**
 * Turns one row of the brand's billing Excel (or the service centre's own tracking sheet) into
 * brand_spares fields.
 *
 * The tracking sheet is typed by hand, so the same thing is written many ways: "WARRANTY" /
 * "WARRENTY", "ow billed 31-08-2026" in the LR date column, "FRESH 07-07-2026" as an inspection
 * number, "3925015116W/WI3925000550" for two numbers in one cell. Whatever is recognised goes into
 * its field; anything left over is kept in the remarks, so nothing typed is lost.
 */
class SpareRowParser
{
    /** Column header (lower case, single spaces) → the field it fills. */
    public const HEADERS = [
        'billing document' => 'billing_doc', 'billing doc' => 'billing_doc', 'billing doc no' => 'billing_doc',
        'billing date' => 'billing_date',
        'material' => 'material_code', 'material code' => 'material_code', 'part code' => 'material_code',
        'item description' => 'description', 'description' => 'description', 'material description' => 'description',
        'ex crm id' => 'ticket_no', 'ticket number' => 'ticket_no', 'ticket no' => 'ticket_no', 'crm id' => 'ticket_no', 'call id' => 'ticket_no',
        'customer name' => 'customer_name', 'customer' => 'customer_name',
        'po number' => 'po_number', 'po no' => 'po_number',
        'issued quantity' => 'quantity', 'quantity' => 'quantity', 'qty' => 'quantity',
        'issued value' => 'value', 'value' => 'value', 'amount' => 'value',
        'warranty' => 'coverage', 'warranty status' => 'coverage', 'coverage' => 'coverage',
        'delivary' => 'delivery_no', 'delivery' => 'delivery_no', 'delivery no' => 'delivery_no',
        'inspection number' => 'inspection', 'inspection no' => 'inspection',
        'lr date' => 'lr_date', 'lr number' => 'lr_number', 'lr no' => 'lr_number',
        'courier' => 'courier',
        'rma number/challan type' => 'rma', 'rma number' => 'rma', 'rma / challan' => 'rma', 'challan' => 'rma',
        'spare used for customer' => 'remarks', 'remarks' => 'remarks',
        'status from company list' => 'company', 'company status' => 'company',
        'ow billed' => 'ow', 'return type' => 'return_type',
    ];

    private const MAX = [
        'billing_doc' => 30, 'material_code' => 40, 'description' => 200, 'delivery_no' => 30, 'po_number' => 40, 'ticket_no' => 40,
        'customer_name' => 150, 'inspection_no' => 120, 'rma_no' => 120, 'lr_number' => 60, 'courier' => 60, 'ow_bill_no' => 30,
    ];

    /** Values that say where the part went rather than being a number; dropped without a note. */
    private const STATUS_WORDS = ['OW', 'STOCK', 'AJ BILLED', 'AJ BILLING', 'OW BILLING DONE'];

    /** Header cell → field name, or null for a column we do not read. */
    public static function field(mixed $header): ?string
    {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) $header)));

        return self::HEADERS[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $raw  field name (from field()) → cell value; dates may be DateTimeInterface
     * @return array<string, mixed>|null  null for a row without a billing document and material
     */
    public function parse(array $raw): ?array
    {
        $doc = $this->id($raw['billing_doc'] ?? null);
        $material = $this->text($raw['material_code'] ?? null);
        if ($doc === null || $material === null) {
            return null;
        }

        $po = $this->text($raw['po_number'] ?? null);
        $customer = $this->text($raw['customer_name'] ?? null);
        $coverage = $this->coverage($this->upper($raw['coverage'] ?? null), strtoupper((string) $po), strtoupper((string) $customer));

        // "ow billed …" was written in whichever column was free.
        $owCells = array_values(array_filter(
            array_map(fn ($k) => $this->upper($raw[$k] ?? null), ['inspection', 'lr_date', 'lr_number', 'courier', 'rma', 'remarks']),
            fn ($s) => $this->isOw($s),
        ));
        $owColumn = $this->upper($raw['ow'] ?? null);
        $owBilled = $coverage === 'warranty' && ($owCells !== [] || ($owColumn !== '' && ! in_array($owColumn, ['NO', 'N', '0'], true)));
        $fresh = str_contains($this->upper($raw['return_type'] ?? null), 'FRESH')
            || array_filter(['inspection', 'lr_number', 'rma'], fn ($k) => str_contains($this->upper($raw[$k] ?? null), 'FRESH')) !== [];

        $notes = [];
        [$inspections, $owBillNo] = $this->inspections($raw['inspection'] ?? null, $notes);
        $rmas = $this->rmas($raw['rma'] ?? null, $notes);
        [$lrNumbers, $lrDate] = $this->lr($raw['lr_number'] ?? null, $this->date($raw['lr_date'] ?? null, strict: true), $notes);
        $courier = $this->courier($raw['courier'] ?? null, $notes);

        $owRaw = $raw['ow'] ?? null;
        $owDate = $owRaw instanceof DateTimeInterface ? $owRaw->format('Y-m-d') : null;
        foreach ([...$owCells, $owColumn] as $s) {
            $owDate ??= $this->date($s);
        }

        $remarks = implode('; ', array_filter([$this->text($raw['remarks'] ?? null), ...$notes])) ?: null;

        return $this->clip([
            'billing_doc' => $doc,
            'billing_date' => $this->date($raw['billing_date'] ?? null),
            'material_code' => strtoupper($material),
            'description' => $this->text($raw['description'] ?? null),
            'quantity' => is_numeric($raw['quantity'] ?? null) ? round((float) $raw['quantity'], 2) : 1,
            'value' => is_numeric($raw['value'] ?? null) ? (int) round((float) $raw['value'] * 100) : 0,
            'delivery_no' => $this->id($raw['delivery_no'] ?? null),
            'po_number' => $po,
            'ticket_no' => $this->id($raw['ticket_no'] ?? null),
            'customer_name' => $customer !== null && str_contains(strtoupper($customer), 'AJ BILL') ? null : $customer,
            'coverage' => $coverage,
            'return_type' => $fresh && $coverage !== 'out_of_warranty' ? 'fresh' : 'defective',
            'inspection_no' => $inspections ? implode(' / ', array_unique($inspections)) : null,
            'rma_no' => $rmas ? implode(' / ', array_unique($rmas)) : null,
            'lr_number' => $lrNumbers ? implode(', ', array_unique($lrNumbers)) : null,
            'lr_date' => $lrDate,
            'courier' => $courier,
            'ow_billed' => $owBilled,
            'ow_billed_on' => $owBilled ? $owDate : null,
            'ow_bill_no' => $owBillNo,
            'company_pending' => str_contains($this->upper($raw['company'] ?? null), 'PENDING'),
            'remarks' => $remarks,
        ]);
    }

    private function coverage(string $w, string $po, string $customer): ?string
    {
        return match (true) {
            str_contains($w, 'AJ BILL') || str_contains($w, 'STOCK') || str_contains($customer, 'AJ BILL') => 'stock',
            str_starts_with($w, 'WAR') || str_starts_with($w, 'AMC') => 'warranty',
            (bool) preg_match('/\bOW\b|OUT OF WARRANTY/', $w) => 'out_of_warranty',
            str_starts_with($po, 'OW') => 'out_of_warranty',
            default => null,
        };
    }

    /** Inspection numbers; a 10-digit 55… number is the brand's OW bill, not an inspection. */
    private function inspections(mixed $cell, array &$notes): array
    {
        $found = [];
        $owBillNo = null;
        foreach ($this->tokens($cell) as $t) {
            if (preg_match('/^55\d{8}$/', $t)) {
                $owBillNo ??= $t;
            } elseif (preg_match('/^(WI|F)?\d{6,}W?$/', $t)) {
                $found[] = $t;
            } elseif ($this->isNote($t)) {
                $notes[] = "Inspection: {$t}";
            }
        }

        return [$found, $owBillNo];
    }

    private function rmas(mixed $cell, array &$notes): array
    {
        $found = [];
        foreach ($this->tokens($cell) as $t) {
            if (preg_match('/^((DC|WI)\d+|\d{6,}W|MANUAL\b.*)$/', $t)) {
                $found[] = $t;
            } elseif ($this->isNote($t)) {
                $notes[] = "RMA: {$t}";
            }
        }

        return $found;
    }

    /** LR numbers look like C812194; a date typed in the LR number column is taken as the LR date. */
    private function lr(mixed $cell, ?string $lrDate, array &$notes): array
    {
        $found = [];
        foreach ($this->tokens($cell) as $t) {
            if (preg_match('/^CI?\d{5,}$/', $t)) {
                $found[] = $t;
            } elseif (($d = $this->date($t, strict: true)) !== null) {
                $lrDate ??= $d;
            } elseif ($this->isNote($t)) {
                $notes[] = "LR: {$t}";
            }
        }

        return [$found, $lrDate];
    }

    private function courier(mixed $cell, array &$notes): ?string
    {
        $courier = $this->text($cell);
        if ($courier === null || ! preg_match('/[A-Z]{3}/i', $courier)) {
            return null;
        }
        $u = strtoupper($courier);
        if ($this->isOw($u) || in_array($u, self::STATUS_WORDS, true)) {
            return null;
        }
        if (in_array($u, ['PENDING', 'DEFECT'], true)) {
            $notes[] = "Courier: {$courier}";

            return null;
        }

        return $courier;
    }

    private function isNote(string $t): bool
    {
        return ! $this->isOw($t) && ! str_starts_with($t, 'FRESH') && ! in_array($t, self::STATUS_WORDS, true);
    }

    private function isOw(string $s): bool
    {
        return (bool) preg_match('/\bOW\s*(BILL|CLAIM)/', $s);
    }

    /** "3925015116W/WI3925000550", "DC2026114496&MANUAL 16-10-2026" → separate upper-case values. */
    private function tokens(mixed $v): array
    {
        if ($v instanceof DateTimeInterface) {
            return [$v->format('d-m-Y')];
        }
        // "24/11/2026" is one date, not three values: write it with dashes before splitting on "/".
        $s = preg_replace('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', '$1-$2-$3', $this->upper($v));

        return $s === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\s*[\/&,]\s*/', $s))));
    }

    /** A date cell, or the first dd-mm-yyyy inside text. Strict: the text must be only a date. */
    private function date(mixed $v, bool $strict = false): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        $pattern = $strict ? '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/' : '/(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/';
        if (preg_match($pattern, $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }

    /** Numbers typed into Excel come back as floats; 14-digit ticket numbers must not turn into 1.4E+13. */
    private function id(mixed $v): ?string
    {
        if (is_float($v) || is_int($v)) {
            return sprintf('%.0f', $v);
        }

        return $this->text($v);
    }

    private function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $v)));

        return $s === '' ? null : $s;
    }

    private function upper(mixed $v): string
    {
        return $v instanceof DateTimeInterface ? $v->format('d-m-Y') : strtoupper((string) $this->text($v));
    }

    private function clip(array $row): array
    {
        foreach (self::MAX as $k => $max) {
            if (is_string($row[$k] ?? null)) {
                $row[$k] = mb_substr($row[$k], 0, $max);
            }
        }

        return $row;
    }
}
