<?php

namespace App\Services\Spares;

use App\Models\Brand;
use App\Models\BrandSpare;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Brings the brand's Excel into brand_spares: the billing export (new part lines), and the brand's
 * "pending returns" list (which lines the brand still waits for). Both can be previewed first.
 */
class SpareImportService
{
    /** Rows read per sheet at most; a year of billing is a few thousand lines. */
    private const MAX_ROWS = 20000;

    /** Values per IN (…) list, well under MySQL's placeholder limit. */
    private const CHUNK = 1000;

    public function __construct(private SpareRowParser $parser) {}

    /**
     * Part lines from every sheet that has the billing columns. A line already in the app (same
     * billing document, material, quantity, value and position) is counted, not added again.
     */
    public function import(UploadedFile $file, User $user, bool $dryRun): array
    {
        $rows = $this->rows($file);
        $existing = [];
        foreach (array_chunk(array_column($rows, 'import_key'), self::CHUNK) as $keys) {
            $existing += BrandSpare::whereIn('import_key', $keys)->pluck('import_key')->flip()->all();
        }
        $new = array_values(array_filter($rows, fn ($r) => ! isset($existing[$r['import_key']])));

        $byStage = array_fill_keys(BrandSpare::STAGES, ['count' => 0, 'value' => 0]);
        foreach ($new as &$r) {
            $r['stage'] = BrandSpare::stageFor($r);
            $byStage[$r['stage']]['count']++;
            $byStage[$r['stage']]['value'] += $r['value'];
        }
        unset($r);

        $summary = [
            'lines' => count($rows),
            'new' => count($new),
            'already_added' => count($rows) - count($new),
            'value' => array_sum(array_column($new, 'value')),
            'linked_to_jobs' => $this->linkedToJobs($new),
            'company_pending' => count(array_filter(array_column($new, 'company_pending'))),
            'by_stage' => $byStage,
            'sample' => array_map(fn ($r) => array_intersect_key($r, array_flip(['billing_doc', 'billing_date', 'material_code', 'description', 'value', 'coverage', 'stage', 'ticket_no', 'customer_name'])), array_slice($new, 0, 8)),
        ];
        if ($dryRun || ! $new) {
            return $summary;
        }

        $brandId = $this->lloydId();
        $tenantId = app(TenantContext::class)->id();
        $now = now();
        DB::transaction(function () use ($new, $brandId, $tenantId, $user, $now) {
            foreach (array_chunk($new, 500) as $chunk) {
                // Bulk insert skips model events, so the tenant and stage are set here.
                BrandSpare::insert(array_map(fn ($r) => $r + [
                    'tenant_id' => $tenantId, 'brand_id' => $brandId, 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
        });
        Audit::log('spares.imported', null, ['file' => $file->getClientOriginalName(), 'lines' => count($new), 'value' => $summary['value']]);

        return $summary;
    }

    /**
     * The brand's list of returns it is still waiting for. It replaces the previous list: lines on
     * it are marked pending, lines that were pending and are no longer on it are cleared.
     */
    public function companyPending(UploadedFile $file, bool $dryRun): array
    {
        $listed = [];
        foreach ($this->rows($file, keyed: false) as $r) {
            $listed[$this->pair($r['billing_doc'], $r['material_code'])] = true;
        }
        if (! $listed) {
            throw ValidationException::withMessages(['file' => 'No part lines found. The sheet needs "Billing Document" and "Material" columns.']);
        }

        $all = BrandSpare::query()->toBase()->get(['id', 'billing_doc', 'material_code', 'company_pending', 'stage', 'value']);
        $isListed = fn ($s) => isset($listed[$this->pair($s->billing_doc, $s->material_code)]);
        $matched = $all->filter($isListed);
        $cleared = $all->filter(fn ($s) => $s->company_pending && ! $isListed($s));
        $found = $matched->map(fn ($s) => $this->pair($s->billing_doc, $s->material_code))->unique()->count();
        $alreadySent = $matched->where('stage', 'sent');

        $summary = [
            'listed' => count($listed),
            'matched' => $matched->count(),
            'not_found' => count($listed) - $found,
            'cleared' => $cleared->count(),
            'already_sent' => $alreadySent->count(),
            'already_sent_value' => (int) $alreadySent->sum('value'),
        ];
        if ($dryRun) {
            return $summary;
        }

        $today = now(app(TenantContext::class)->tenant()->timezone)->toDateString();
        DB::transaction(function () use ($matched, $cleared, $today) {
            foreach ([[$matched, true], [$cleared, false]] as [$set, $pending]) {
                foreach ($set->pluck('id')->chunk(self::CHUNK) as $ids) {
                    BrandSpare::whereIn('id', $ids)->update(['company_pending' => $pending, 'company_checked_on' => $today, 'updated_at' => now()]);
                }
            }
        });
        Audit::log('spares.company_list', null, ['pending' => $summary['matched'], 'cleared' => $summary['cleared']]);

        return $summary;
    }

    public function lloydId(): ?int
    {
        return Brand::where('name', 'like', '%lloyd%')->value('id');
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

    /** Parsed part lines from every sheet that has a "Billing Document" and a "Material" column. */
    private function rows(UploadedFile $file, bool $keyed = true): array
    {
        try {
            // Only real spreadsheet formats; the HTML and XML readers are never tried.
            $reader = IOFactory::createReaderForFile($file->getRealPath(), [IOFactory::READER_XLSX, IOFactory::READER_XLS, IOFactory::READER_CSV]);
            $book = $reader->load($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'This file could not be opened. Upload the Excel file (.xlsx, .xls or .csv) exported from the brand portal.']);
        }

        $rows = [];
        $seen = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            if ($sheet->getHighestDataRow() > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => "The sheet \"{$sheet->getTitle()}\" has more than ".number_format(self::MAX_ROWS).' rows. Split it into smaller files and upload them one after another.']);
            }
            foreach ($this->sheetRows($sheet) as $raw) {
                $row = $this->parser->parse($raw);
                if ($row === null) {
                    continue;
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

        return array_values($rows);
    }

    /** @return iterable<array<string, mixed>> field → value for each row under the header row */
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
        $dateFields = ['billing_date', 'lr_date', 'lr_number', 'inspection', 'rma', 'ow'];
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
            yield $raw;
        }
    }

    private function pair(string $doc, string $material): string
    {
        return strtoupper(trim($doc)).'|'.strtoupper(trim($material));
    }
}
