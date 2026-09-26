<?php

namespace App\Services\Reports;

use App\Exports\ReportSheetExport;
use App\Models\Tenant;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Single export pipeline for every report (FR-13.8): .xlsx and .pdf.
 */
class ReportExporter
{
    public function render(ReportResult $report, Tenant $tenant, string $format): string
    {
        return $format === 'xlsx'
            ? Excel::raw(new ReportSheetExport($report, $tenant->name), ExcelFormat::XLSX)
            : $this->pdf($report, $tenant);
    }

    public function filename(ReportResult $report, string $format): string
    {
        return str($report->title)->slug().'-'.now()->format('Ymd-His').'.'.$format;
    }

    private function pdf(ReportResult $report, Tenant $tenant): string
    {
        $format = function ($value, string $type) use ($tenant) {
            return match (true) {
                $value === null || $value === '' => '–',
                $type === 'money' => Money::format((int) $value, $tenant->currency),
                $type === 'quantity' => rtrim(rtrim(number_format((float) $value, 3), '0'), '.'),
                $type === 'number' => is_float($value) ? number_format($value, 1) : number_format((int) $value),
                default => (string) $value,
            };
        };
        $wide = count($report->columns) > 7;

        return Pdf::loadView('pdf.report', compact('report', 'tenant', 'format'))
            ->setPaper('a4', $wide ? 'landscape' : 'portrait')
            ->output();
    }
}
