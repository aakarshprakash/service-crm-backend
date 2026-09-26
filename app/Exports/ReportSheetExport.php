<?php

namespace App\Exports;

use App\Services\Reports\ReportResult;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Generic .xlsx renderer for any ReportResult (FR-13.8). Money is exported as
 * real numbers in major units so spreadsheet formulas work on it.
 */
class ReportSheetExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithStyles, WithTitle
{
    private const HEADER_ROW = 4;

    public function __construct(private ReportResult $report, private string $company) {}

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $this->report->title), 0, 31);
    }

    public function headings(): array
    {
        return [
            [$this->company],
            [$this->report->title.' · '.$this->report->subtitle],
            [],
            array_map(fn ($c) => $c[0], $this->report->columns),
        ];
    }

    public function array(): array
    {
        $keys = array_keys($this->report->columns);

        return array_map(function ($row) use ($keys) {
            return array_map(function ($key) use ($row) {
                $value = $row[$key] ?? null;
                $type = $this->report->columns[$key][1] ?? 'text';

                return match (true) {
                    $value === null => null,
                    $type === 'money' => round($value / 100, 2),
                    default => $value,
                };
            }, $keys);
        }, $this->report->rows);
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach (array_values($this->report->columns) as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $formats[$letter] = match ($col[1] ?? 'text') {
                'money' => '#,##0.00',
                'quantity' => '#,##0.###',
                'number' => '#,##0.##',
                default => '@',
            };
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            2 => ['font' => ['italic' => true, 'color' => ['rgb' => '555555']]],
            self::HEADER_ROW => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A8A']],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = Coordinate::stringFromColumnIndex(count($this->report->columns));
                $sheet->freezePane('A'.(self::HEADER_ROW + 1));
                $sheet->setAutoFilter('A'.self::HEADER_ROW.':'.$lastCol.(self::HEADER_ROW + count($this->report->rows)));

                // Highlight flagged rows (discrepancies, low stock, overdue).
                foreach ($this->report->rows as $i => $row) {
                    if (! empty($row['_flag'])) {
                        $r = self::HEADER_ROW + 1 + $i;
                        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
                    }
                }

                // Summary block below the data.
                $line = self::HEADER_ROW + count($this->report->rows) + 2;
                foreach ($this->report->summary as $label => $value) {
                    $sheet->setCellValue("A{$line}", $label);
                    $sheet->setCellValueExplicit("B{$line}", (string) $value, DataType::TYPE_STRING);
                    $sheet->getStyle("A{$line}")->getFont()->setBold(true);
                    $line++;
                }
            },
        ];
    }
}
