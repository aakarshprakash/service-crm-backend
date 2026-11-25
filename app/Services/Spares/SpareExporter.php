<?php

namespace App\Services\Spares;

use App\Models\BrandSpare;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Part lines as an .xlsx in the same columns as the tracking sheet staff used before, so the file
 * can be shared with the brand as it always was, and uploaded back without losing anything.
 */
class SpareExporter
{
    private const HEADERS = [
        'Billing Document', 'Billing Date', 'Material', 'Item Description', 'EX CRM ID', 'CUSTOMER NAME', 'PO NUMBER',
        'Issued Quantity', 'Issued Value', 'WARRANTY', 'DELIVARY', 'INSPECTION NUMBER', 'LR DATE', 'LR NUMBER', 'COURIER ',
        'RMA NUMBER/CHALLAN TYPE', 'SPARE USED FOR CUSTOMER', 'STATUS FROM COMPANY LIST', 'OW BILLED', 'RETURN TYPE', 'STAGE', 'JOB',
    ];

    private const COVERAGE = ['warranty' => 'WARRANTY', 'out_of_warranty' => 'OW', 'stock' => 'STOCK'];

    private const STAGE = ['unsorted' => 'Not sorted', 'inspect' => 'To inspect', 'ready' => 'Ready to send', 'sent' => 'Sent', 'closed' => 'Closed'];

    /** Writes the file and returns its path. */
    public function write(Builder $query): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Brand spares');
        $sheet->fromArray(self::HEADERS);
        $sheet->getStyle('A1:V1')->getFont()->setBold(true);
        $sheet->getStyle('A1:V1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $sheet->freezePane('A2');

        $r = 2;
        $query->with('job:id,crm_call_id')->chunkById(1000, function ($spares) use ($sheet, &$r) {
            foreach ($spares as $s) {
                /** @var BrandSpare $s */
                $cells = [
                    $s->billing_doc, $s->billing_date, $s->material_code, $s->description, $s->ticket_no, $s->customer_name, $s->po_number,
                    $s->quantity, $s->value / 100, self::COVERAGE[$s->coverage] ?? null, $s->delivery_no, $s->inspection_no,
                    $s->lr_date, $s->lr_number, $s->courier, $s->rma_no, $s->remarks, $s->company_pending ? 'PENDING' : null,
                    $s->ow_billed ? trim('ow billed '.($s->ow_billed_on?->format('d-m-Y') ?? '')) : null,
                    $s->return_type === 'fresh' ? 'FRESH' : null, self::STAGE[$s->stage] ?? $s->stage, $s->job?->crm_call_id,
                ];
                foreach ($cells as $c => $value) {
                    $cell = $sheet->getCell([$c + 1, $r]);
                    if ($value instanceof \DateTimeInterface) {
                        $cell->setValue(ExcelDate::PHPToExcel($value));
                        $cell->getStyle()->getNumberFormat()->setFormatCode('dd-mm-yyyy');
                    } elseif (is_string($value)) {
                        // Long numbers (tickets, documents) stay text, so Excel does not round them.
                        $cell->setValueExplicit($value, DataType::TYPE_STRING);
                    } elseif ($value !== null) {
                        $cell->setValue($value);
                    }
                }
                $r++;
            }
        });

        $sheet->getStyle("I2:I{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A' => 13, 'B' => 12, 'C' => 15, 'D' => 42, 'E' => 17, 'F' => 26, 'G' => 16, 'J' => 11, 'L' => 22, 'P' => 22, 'Q' => 30, 'U' => 14] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $path = tempnam(sys_get_temp_dir(), 'spares'); // the download sends it as .xlsx and deletes it
        (new Xlsx($book))->save($path);

        return $path;
    }
}
