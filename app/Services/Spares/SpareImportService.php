<?php

namespace App\Services\Spares;

use App\Models\Brand;
use App\Models\BrandSpare;
use App\Models\ServiceJob;
use App\Models\SpareImport;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Brings Excel into SAPRE: the brand's billing export, the SAPRE template (new lines and updates to
 * lines already here) and the brand's "pending returns" list. Every upload can be previewed first
 * and is kept in the upload history.
 *
 * Update rule: a filled cell overwrites the stored value; a blank cell never clears one. An OW stage
 * only moves forward (marked → billed → paid).
 */
class SpareImportService
{
    /** Rows read per sheet at most; a year of billing is a few thousand lines. */
    private const MAX_ROWS = 20000;

    /** Values per IN (…) list, well under MySQL's placeholder limit. */
    private const CHUNK = 1000;

    /** Refused rows kept with an upload. */
    private const MAX_PROBLEMS = 200;

    /** Fields an upload may change on a line already in the app (the key fields never change). */
    private const UPDATABLE = [
        'billing_date', 'description', 'delivery_no', 'po_number', 'received_on', 'ticket_no', 'customer_name', 'coverage',
        'inspection_no', 'inspection_on', 'rma_no', 'rma_on', 'lr_number', 'lr_date', 'courier', 'return_received_on',
        'replacement_doc', 'replacement_material', 'replacement_on', 'ow_billed_on', 'ow_bill_no', 'ow_paid_on', 'ow_payment_ref', 'remarks',
    ];

    public function __construct(private SpareRowParser $parser) {}

    /**
     * New part lines are added; lines already here are updated from the filled cells. Rows that
     * cannot be read are listed with the reason, and the rest still go in.
     */
    public function import(UploadedFile $file, User $user, bool $dryRun): array
    {
        ['rows' => $rows, 'problems' => $problems, 'colours' => $colours] = $this->rows($file);

        $existing = collect();
        foreach (array_chunk(array_column($rows, 'import_key'), self::CHUNK) as $keys) {
            $existing = $existing->merge(BrandSpare::whereIn('import_key', $keys)->get()->keyBy('import_key'));
        }

        $new = [];
        $updates = [];
        foreach ($rows as $row) {
            $spare = $existing[$row['import_key']] ?? null;
            if (! $spare) {
                $row['status'] = BrandSpare::statusFor($row);
                $new[] = $row;
            } elseif ($changes = $this->changes($spare, $row)) {
                $updates[] = [$spare, $changes];
            }
        }

        $byStatus = array_fill_keys(BrandSpare::STATUSES, 0);
        foreach ($new as $r) {
            $byStatus[$r['status']]++;
        }
        $summary = [
            'lines' => count($rows),
            'new' => count($new),
            'updated' => count($updates),
            'unchanged' => count($rows) - count($new) - count($updates),
            'rejected' => count($problems),
            'problems' => array_slice($problems, 0, self::MAX_PROBLEMS),
            'value' => array_sum(array_column($new, 'value')),
            'linked_to_jobs' => $this->linkedToJobs($new),
            'company_pending' => count(array_filter(array_column($new, 'company_pending'))),
            'by_status' => $byStatus,
            'colours' => $colours,
            'changes' => array_map(fn ($u) => [
                'billing_doc' => $u[0]->billing_doc, 'material_code' => $u[0]->material_code, 'fields' => array_keys($u[1]),
            ], array_slice($updates, 0, 8)),
        ];
        if ($dryRun || (! $new && ! $updates)) {
            return $summary;
        }

        $brandId = $this->lloydId();
        $tenantId = app(TenantContext::class)->id();
        $now = now();
        DB::transaction(function () use ($new, $updates, $brandId, $tenantId, $user, $now, $file, $summary) {
            foreach (array_chunk($new, 500) as $chunk) {
                // Bulk insert skips model events, so the tenant and status are set here.
                BrandSpare::insert(array_map(fn ($r) => $r + [
                    'tenant_id' => $tenantId, 'brand_id' => $brandId, 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            foreach ($updates as [$spare, $changes]) {
                $spare->fill($changes)->save(); // one at a time, so the status is worked out and the change is in the activity log
            }
            SpareImport::create([
                'user_id' => $user->id, 'kind' => 'bill', 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'lines' => $summary['lines'], 'added' => $summary['new'], 'updated' => $summary['updated'],
                'unchanged' => $summary['unchanged'], 'rejected' => $summary['rejected'], 'problems' => $summary['problems'] ?: null,
            ]);
        });

        return $summary;
    }

    /**
     * The brand's list of returns it is still waiting for. It replaces the previous list: lines on
     * it are marked pending; lines that were pending and are no longer on it are cleared, and a sent
     * part cleared from the list counts as received by Lloyd.
     */
    public function companyPending(UploadedFile $file, User $user, bool $dryRun): array
    {
        $listed = [];
        foreach ($this->rows($file, keyed: false)['rows'] as $r) {
            $listed[$this->pair($r['billing_doc'], $r['material_code'])] = true;
        }
        if (! $listed) {
            throw ValidationException::withMessages(['file' => 'No part lines found. The sheet needs "Billing Document" and "Material" columns.']);
        }

        $all = BrandSpare::query()->toBase()->get(['id', 'billing_doc', 'material_code', 'company_pending', 'status', 'value']);
        $isListed = fn ($s) => isset($listed[$this->pair($s->billing_doc, $s->material_code)]);
        $matched = $all->filter($isListed);
        $cleared = $all->filter(fn ($s) => $s->company_pending && ! $isListed($s));
        $received = $cleared->where('status', 'pending_return');
        $found = $matched->map(fn ($s) => $this->pair($s->billing_doc, $s->material_code))->unique()->count();
        $alreadySent = $matched->where('status', 'pending_return');

        $summary = [
            'listed' => count($listed),
            'matched' => $matched->count(),
            'not_found' => count($listed) - $found,
            'cleared' => $cleared->count(),
            'received' => $received->count(),
            'already_sent' => $alreadySent->count(),
            'already_sent_value' => (int) $alreadySent->sum('value'),
        ];
        if ($dryRun) {
            return $summary;
        }

        $today = now(app(TenantContext::class)->tenant()->timezone)->toDateString();
        DB::transaction(function () use ($matched, $cleared, $received, $today, $user, $file, $summary) {
            foreach ([[$matched, true], [$cleared, false]] as [$set, $pending]) {
                foreach ($set->pluck('id')->chunk(self::CHUNK) as $ids) {
                    BrandSpare::whereIn('id', $ids)->update(['company_pending' => $pending, 'company_checked_on' => $today, 'updated_at' => now()]);
                }
            }
            // Gone from Lloyd's list after being sent: Lloyd has it.
            foreach ($received->pluck('id')->chunk(self::CHUNK) as $ids) {
                BrandSpare::whereIn('id', $ids)->get()->each(fn (BrandSpare $s) => $s->fill(['return_received_on' => $today])->save());
            }
            SpareImport::create([
                'user_id' => $user->id, 'kind' => 'pending', 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'lines' => $summary['listed'], 'added' => 0, 'updated' => $summary['matched'] + $summary['cleared'], 'unchanged' => 0,
                'rejected' => $summary['not_found'],
            ]);
        });

        return $summary;
    }

    public function lloydId(): ?int
    {
        return Brand::where('name', 'like', '%lloyd%')->value('id');
    }

    /** The fields an uploaded row changes on a stored line: filled cells only, OW stage forward only. */
    private function changes(BrandSpare $spare, array $row): array
    {
        $changes = [];
        foreach (self::UPDATABLE as $field) {
            $new = $row[$field] ?? null;
            if ($new === null || $new === '') {
                continue;
            }
            $old = $spare->getAttribute($field);
            $old = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old;
            if ((string) $old !== (string) $new) {
                $changes[$field] = $new;
            }
        }
        if ($row['return_type'] === 'fresh' && $spare->return_type !== 'fresh') {
            $changes['return_type'] = 'fresh';
        }
        if ($row['ow_billed'] && ! $spare->ow_billed) {
            $changes['ow_billed'] = true;
        }
        if ($row['company_pending'] && ! $spare->company_pending) {
            $changes['company_pending'] = true;
        }
        $rank = array_flip(BrandSpare::OW_STAGES);
        if ($row['ow_stage'] && ($spare->ow_stage === null || $rank[$row['ow_stage']] > $rank[$spare->ow_stage])) {
            $changes['ow_stage'] = $row['ow_stage'];
        }

        return $changes;
    }

    /** How many of these lines have a job booked under their ticket number. */
    private function linkedToJobs(array $rows): int
    {
        $tickets = array_values(array_unique(array_filter(array_column($rows, 'ticket_no'))));
        $found = [];
        foreach (array_chunk($tickets, self::CHUNK) as $chunk) {
            $found += ServiceJob::whereIn('crm_call_id', $chunk)->pluck('id', 'crm_call_id')->all();
        }

        return count(array_filter($rows, fn ($r) => $r['ticket_no'] !== null && isset($found[$r['ticket_no']])));
    }

    /**
     * Parsed part lines from every sheet that has a "Billing Document" and a "Material" column,
     * the rows refused with the reason, and how many rows had each colour.
     *
     * @return array{rows: array<int, array>, problems: array<int, array>, colours: array<string, int>}
     */
    private function rows(UploadedFile $file, bool $keyed = true): array
    {
        try {
            // Only real spreadsheet formats; the HTML and XML readers are never tried.
            $reader = IOFactory::createReaderForFile($file->getRealPath(), [IOFactory::READER_XLSX, IOFactory::READER_XLS, IOFactory::READER_CSV]);
            $book = $reader->load($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'This file could not be opened. Upload the Excel file (.xlsx, .xls or .csv) exported from the brand portal or the SAPRE template.']);
        }

        $rows = [];
        $problems = [];
        $colours = [];
        $seen = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            if ($sheet->getHighestDataRow() > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => "The sheet \"{$sheet->getTitle()}\" has more than ".number_format(self::MAX_ROWS).' rows. Split it into smaller files and upload them one after another.']);
            }
            foreach ($this->sheetRows($sheet) as $rowNumber => $raw) {
                if ($reason = $this->parser->problem($raw)) {
                    $problems[] = ['sheet' => $sheet->getTitle(), 'row' => $rowNumber, 'reason' => $reason];

                    continue;
                }
                $row = $this->parser->parse($raw);
                if ($row === null) {
                    continue;
                }
                if ($raw['colour'] ?? null) {
                    $colours[$raw['colour']] = ($colours[$raw['colour']] ?? 0) + 1;
                }
                if ($keyed) {
                    // The same part can be billed twice on one document; the n-th copy keeps its own key.
                    $base = implode('|', [$row['billing_doc'], $row['material_code'], $row['quantity'], $row['value']]);
                    $n = $seen[$sheet->getTitle()][$base] = ($seen[$sheet->getTitle()][$base] ?? 0) + 1;
                    $row['import_key'] = BrandSpare::importKey($row['billing_doc'], $row['material_code'], $row['quantity'], $row['value'], $n);
                    if (isset($rows[$row['import_key']])) {
                        continue; // the same line on another sheet of this file
                    }
                }
                $rows[$keyed ? $row['import_key'] : count($rows)] = $row;
            }
        }

        if ($keyed && ! $rows) {
            throw ValidationException::withMessages(['file' => 'No part lines found. The sheet needs "Billing Document" and "Material" columns.']);
        }

        return ['rows' => array_values($rows), 'problems' => $problems, 'colours' => $colours];
    }

    /** @return iterable<int, array<string, mixed>> Excel row number → field → value, for each row under the header */
    private function sheetRows(Worksheet $sheet): iterable
    {
        $lastRow = $sheet->getHighestDataRow();
        $lastCol = $sheet->getHighestDataColumn();
        // Calculated values, so a total typed as a formula reads as its number.
        $grid = $sheet->rangeToArray("A1:{$lastCol}{$lastRow}", null, true, false, false);

        // The header is within the first ten rows and names at least the document and the material.
        $columns = null;
        foreach (array_slice($grid, 0, 10, true) as $i => $cells) {
            $map = [];
            foreach ($cells as $c => $header) {
                $field = SpareRowParser::field($header);
                if ($field && ! in_array($field, $map, true)) {
                    $map[$c] = $field;
                }
            }
            if (in_array('billing_doc', $map, true) && in_array('material_code', $map, true)) {
                $columns = [$i, $map];
                break;
            }
        }
        if ($columns === null) {
            return;
        }

        [$headerIndex, $map] = $columns;
        $docColumn = array_search('billing_doc', $map, true) + 1;
        $dateFields = ['billing_date', 'received_on', 'lr_date', 'lr_number', 'inspection', 'inspection_on', 'rma', 'rma_on', 'ow',
            'ow_paid_on', 'return_received_on', 'replacement_on'];
        foreach (array_slice($grid, $headerIndex + 1, null, true) as $i => $cells) {
            $raw = [];
            foreach ($map as $c => $field) {
                $value = $cells[$c] ?? null;
                if (is_numeric($value) && in_array($field, $dateFields, true)
                    && ExcelDate::isDateTime($sheet->getCell([$c + 1, $i + 1]))) {
                    $value = ExcelDate::excelToDateTimeObject($value);
                }
                $raw[$field] = $value;
            }
            // The old tracking sheet colours the whole row; the billing document cell carries it.
            $fill = $sheet->getStyle([$docColumn, $i + 1])->getFill();
            $raw['colour'] = $fill->getFillType() === Fill::FILL_NONE ? null : SpareRowParser::colourFamily($fill->getStartColor()->getARGB());
            yield $i + 1 => $raw;
        }
    }

    private function pair(string $doc, string $material): string
    {
        return strtoupper(trim($doc)).'|'.strtoupper(trim($material));
    }
}
