<?php

namespace App\Services\Spares;

use App\Models\BrandSpare;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The SAPRE Excel: the export of part lines and the blank template use the same columns, so a
 * downloaded file can be filled in and uploaded back to update the same lines. Rows are coloured
 * the way the office always coloured them: blue warranty, orange marked OW, light blue billed by
 * Lloyd, grey paid.
 */
class SpareExporter
{
    /** Column header => how to read it from a line. The headers are the ones SpareRowParser reads. */
    private const COLUMNS = [
        'Billing Document' => 'billing_doc', 'Billing Date' => 'billing_date', 'Material' => 'material_code',
        'Item Description' => 'description', 'EX CRM ID' => 'ticket_no', 'CUSTOMER NAME' => 'customer_name', 'PO NUMBER' => 'po_number',
        'Issued Quantity' => 'quantity', 'Issued Value' => 'value', 'REC DATE' => 'received_on', 'WARRANTY' => 'coverage',
        'DELIVARY' => 'delivery_no', 'INSPECTION NUMBER' => 'inspection_no', 'INSPECTION DATE' => 'inspection_on',
        'RMA NUMBER/CHALLAN TYPE' => 'rma_no', 'RMA DATE' => 'rma_on', 'RETURN TYPE' => 'return_type', 'COURIER' => 'courier',
        'LR NUMBER' => 'lr_number', 'LR DATE' => 'lr_date', 'RETURN RECEIVED ON' => 'return_received_on',
        'REPLACEMENT DOC' => 'replacement_doc', 'REPLACEMENT MATERIAL' => 'replacement_material', 'REPLACEMENT DATE' => 'replacement_on',
        'OW STAGE' => 'ow_stage', 'OW BILLED ON' => 'ow_billed_on', 'OW BILL NO' => 'ow_bill_no', 'OW PAID ON' => 'ow_paid_on',
        'PAYMENT REF' => 'ow_payment_ref', 'STATUS FROM COMPANY LIST' => 'company_pending', 'SPARE USED FOR CUSTOMER' => 'remarks',
        'STATUS' => 'status', 'JOB' => 'job',
    ];

    public const COVERAGE = ['warranty' => 'WARRANTY', 'out_of_warranty' => 'OW', 'stock' => 'STOCK'];

    public const OW_STAGE = ['marked' => 'Marked OW (orange)', 'billed' => 'Billed by Lloyd (light blue)', 'paid' => 'Paid (grey)'];

    public const STATUS = [
        'unsorted' => 'Not sorted', 'pending_inspection' => 'Pending inspection', 'pending_dispatch' => 'Pending dispatch',
        'pending_return' => 'Pending return', 'return_completed' => 'Return completed', 'replacement_completed' => 'Replacement completed',
        'ow_billing_pending' => 'OW billing pending', 'payment_pending' => 'Payment pending', 'completed' => 'Process completed',
    ];

    /** The client's colours: fill, and a text colour that reads on it. */
    private const ROW_COLOUR = [
        'blue' => ['0070C0', 'FFFFFF'], 'orange' => ['FFC000', '000000'], 'light_blue' => ['9DC3E6', '000000'], 'grey' => ['A6A6A6', '000000'],
    ];

    /** Writes the part lines the query matches and returns the file's path. */
    public function write(Builder $query): string
    {
        $book = new Spreadsheet;
        $sheet = $this->sheet($book, 'SAPRE spare parts');

        $r = 2;
        $query->with('job:id,crm_call_id')->orderBy('billing_date')->orderBy('id')->chunkById(1000, function ($spares) use ($sheet, &$r) {
            foreach ($spares as $spare) {
                $this->writeRow($sheet, $r++, $spare);
            }
        });
        $this->validations($sheet, max($r, 500));

        return $this->save($book);
    }

    /** A blank template with the same columns, dropdowns for the fixed values, and how to fill it. */
    public function template(): string
    {
        $book = new Spreadsheet;
        $this->validations($this->sheet($book, 'SAPRE spare parts'), 2000);

        $help = $book->createSheet()->setTitle('How to fill');
        $help->fromArray([
            ['How to fill the SAPRE template'],
            [''],
            ['One row for each part line on a Lloyd bill. Billing Document and Material are needed on every row.'],
            ['Dates as dd-mm-yyyy. Issued Value in rupees.'],
            ['WARRANTY: WARRANTY, OW or STOCK (pick from the list).'],
            ['OW STAGE, for OW parts: Marked OW (orange), Billed by Lloyd (light blue), Paid (grey).'],
            ['Uploading a row that is already in SAPRE updates it. A blank cell never clears what is saved.'],
            ['STATUS and JOB are filled in by SAPRE and are not read back.'],
            [''],
            ['Colours (the same as the old sheet)'],
            ['Blue', 'Warranty: faulty part returned through RMA inspection, manual or fresh return, replacement after inspection'],
            ['Orange', 'Marked OW during data entry'],
            ['Light blue', 'OW billing done by Lloyd from our data (payment pending)'],
            ['Grey', 'OW billing done by Lloyd after payment'],
        ]);
        $help->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $help->getStyle('A10')->getFont()->setBold(true);
        foreach (['blue' => 11, 'orange' => 12, 'light_blue' => 13, 'grey' => 14] as $colour => $row) {
            $this->paint($help, "A{$row}", $colour);
        }
        $help->getColumnDimension('A')->setWidth(16);
        $help->getColumnDimension('B')->setWidth(110);
        $book->setActiveSheetIndex(0);

        return $this->save($book);
    }

    private function sheet(Spreadsheet $book, string $title): Worksheet
    {
        $sheet = $book->getActiveSheet()->setTitle($title);
        $sheet->fromArray(array_keys(self::COLUMNS));
        $last = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $sheet->freezePane('A2');
        foreach (['A' => 13, 'B' => 12, 'C' => 15, 'D' => 40, 'E' => 17, 'F' => 26, 'G' => 16, 'K' => 11, 'M' => 22, 'O' => 22,
            'Y' => 26, 'AE' => 30, 'AF' => 20] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        return $sheet;
    }

    private function writeRow(Worksheet $sheet, int $r, BrandSpare $s): void
    {
        $c = 1;
        foreach (self::COLUMNS as $field) {
            $value = match ($field) {
                'value' => $s->value / 100,
                'coverage' => self::COVERAGE[$s->coverage] ?? null,
                'return_type' => $s->return_type === 'fresh' ? 'FRESH' : null,
                'ow_stage' => self::OW_STAGE[$s->ow_stage] ?? null,
                'company_pending' => $s->company_pending ? 'PENDING' : null,
                'status' => self::STATUS[$s->status] ?? $s->status,
                'job' => $s->job?->crm_call_id,
                default => $s->getAttribute($field),
            };
            $cell = $sheet->getCell([$c++, $r]);
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
        if ($colour = $this->colourOf($s)) {
            $this->paint($sheet, "A{$r}:".$sheet->getHighestColumn().$r, $colour);
        }
    }

    private function colourOf(BrandSpare $s): ?string
    {
        if (BrandSpare::onOwPath($s->getAttributes())) {
            return match ($s->ow_stage ?? ($s->ow_billed ? 'billed' : 'marked')) {
                'paid' => 'grey',
                'billed' => 'light_blue',
                default => 'orange',
            };
        }

        return $s->coverage === 'warranty' ? 'blue' : null;
    }

    private function paint(Worksheet $sheet, string $range, string $colour): void
    {
        [$fill, $text] = self::ROW_COLOUR[$colour];
        $style = $sheet->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        $style->getFont()->getColor()->setRGB($text);
    }

    /** Dropdowns for the columns with fixed values. */
    private function validations(Worksheet $sheet, int $rows): void
    {
        $lists = [
            'WARRANTY' => array_values(self::COVERAGE),
            'RETURN TYPE' => ['FRESH', 'DEFECTIVE'],
            'OW STAGE' => array_values(self::OW_STAGE),
            'STATUS FROM COMPANY LIST' => ['PENDING'],
        ];
        $headers = array_keys(self::COLUMNS);
        foreach ($lists as $header => $values) {
            $col = Coordinate::stringFromColumnIndex(array_search($header, $headers, true) + 1);
            $rule = new DataValidation;
            $rule->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)
                ->setErrorTitle('Pick from the list')->setError('Choose one of the values in the list.')
                ->setFormula1('"'.implode(',', $values).'"');
            $sheet->setDataValidation("{$col}2:{$col}{$rows}", $rule);
        }
    }

    private function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spares'); // sent as .xlsx and deleted after the download
        (new Xlsx($book))->save($path);

        return $path;
    }
}
