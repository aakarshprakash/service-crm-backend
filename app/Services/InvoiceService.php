<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobImage;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\UpiAccount;
use App\Support\Gst;
use App\Support\IndianStates;
use App\Support\Money;
use App\Support\QrCode;
use App\Support\DocumentNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /**
     * Create or refresh the job's invoice from all closed visits (FR-10.1):
     * Total Service Charge + Total Spare Charge = Total Charge.
     */
    public function syncForJob(ServiceJob $job): ?Invoice
    {
        return DB::transaction(function () use ($job) {
            $visits = $job->visits()->where('status', '!=', 'in_progress')->get();
            $service = (int) $visits->sum('labour_charge');
            $spare = (int) $visits->sum('spare_charge');
            $total = $service + $spare;

            $invoice = Invoice::where('job_id', $job->id)->lockForUpdate()->first();
            if (! $invoice && $total === 0) {
                return null;
            }

            if (! $invoice) {
                $tenant = Tenant::findOrFail($job->tenant_id);
                $invoice = new Invoice([
                    'job_id' => $job->id,
                    'customer_id' => $job->customer_id,
                    'branch_id' => $job->branch_id,
                    'invoice_number' => DocumentNumber::next($tenant, 'invoice'),
                    'pay_token' => Str::random(48),
                    'generated_at' => now(),
                    'is_gst' => $this->startsAsGst($tenant, 'job_bills'),
                ]);
            }

            $invoice->fill([
                'total_service_charge' => $service,
                'total_spare_charge' => $spare,
                'total_amount' => $total,
                'is_credit' => $visits->contains('payment_method', 'credit'),
            ]);
            $invoice->save();
            $this->applyGst($invoice);
            $invoice->refreshPaymentTotals();

            return $invoice;
        });
    }

    /**
     * Snapshot the GST breakup of the invoice (prices are GST-inclusive, so the total is unchanged).
     * A non-GST bill, or any bill from a company without a GSTIN, is a plain invoice with no tax.
     */
    public function applyGst(Invoice $invoice): void
    {
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        if (! $tenant->gstin || ! $invoice->is_gst) {
            $invoice->forceFill([
                'is_gst' => false, 'taxable_amount' => $invoice->total_amount, 'cgst_amount' => 0, 'sgst_amount' => 0,
                'igst_amount' => 0, 'place_of_supply' => null, 'customer_gstin' => null, 'gst_breakup' => null,
            ])->save();

            return;
        }

        $customer = Customer::withTrashed()->find($invoice->customer_id);
        $ours = IndianStates::code($tenant->gstin) ?? IndianStates::code($tenant->state);
        $theirs = IndianStates::code($customer?->gstin) ?? IndianStates::code($customer?->state);
        $interState = $ours && $theirs && $ours !== $theirs;

        $gst = Gst::breakup($this->gstLines($invoice, $tenant), $invoice->discount_amount, $interState);
        if ($gst['totals']['total'] !== $invoice->total_amount) {
            // By construction this cannot happen; never issue a tax invoice that does not add up.
            throw new \RuntimeException("GST breakup {$gst['totals']['total']} does not match invoice {$invoice->invoice_number} total {$invoice->total_amount}.");
        }

        $invoice->forceFill([
            'is_gst' => true,
            'taxable_amount' => $gst['totals']['taxable'],
            'cgst_amount' => $gst['totals']['cgst'],
            'sgst_amount' => $gst['totals']['sgst'],
            'igst_amount' => $gst['totals']['igst'],
            'place_of_supply' => IndianStates::name($theirs ?? $ours),
            'customer_gstin' => $customer?->gstin ?: null,
            'gst_breakup' => ['lines' => $gst['lines'], 'summary' => $gst['summary']],
        ])->save();
    }

    /** Whether a new bill of this kind ('job_bills' or 'walkin_bills') starts as a GST bill. */
    public function startsAsGst(Tenant $tenant, string $kind): bool
    {
        return (bool) $tenant->gstin && $tenant->setting("gst.{$kind}") !== 'non_gst';
    }

    /**
     * Switches a bill between GST and non-GST. Prices include GST, so what the customer pays
     * doesn't change: only whether the tax is split out and shown.
     */
    public function changeBillType(Invoice $invoice, bool $isGst): Invoice
    {
        if ($isGst && ! Tenant::findOrFail($invoice->tenant_id)->gstin) {
            throw ValidationException::withMessages(['is_gst' => 'Add your GSTIN under Settings → Company to issue GST bills.']);
        }
        $invoice->is_gst = $isGst;
        $this->applyGst($invoice);

        return $invoice;
    }

    /** Invoice lines at their GST-inclusive amounts: a walk-in bill's own items, or a job's visits. */
    private function gstLines(Invoice $invoice, Tenant $tenant): array
    {
        $sac = (string) $tenant->setting('gst.service_sac', '998719');
        $serviceRate = (float) $tenant->setting('gst.service_rate', 18);
        $partRate = (float) $tenant->setting('gst.part_rate', 18);
        $part = fn ($item) => ['hsn' => $item?->hsn_code, 'uom' => $item?->unit_of_measure, 'gst_rate' => $item?->gst_rate !== null ? (float) $item->gst_rate : $partRate];

        if (! $invoice->job_id) {
            return $invoice->items()->with('item')->orderBy('id')->get()->map(fn ($l) => [
                'type' => $l->type,
                'description' => $l->description,
                'qty' => (float) $l->quantity,
                'gross' => (int) $l->total,
            ] + ($l->type === 'part' ? $part($l->item) : ['hsn' => $sac, 'gst_rate' => $serviceRate]))->all();
        }

        $lines = [];
        $visits = $invoice->job->visits()->where('status', '!=', 'in_progress')->with(['actionTaken', 'inventoryUsage.item'])->orderBy('id')->get();
        foreach ($visits as $visit) {
            if ((int) $visit->labour_charge !== 0) {
                $lines[] = ['type' => 'service', 'description' => 'Service charge'.($visit->actionTaken ? ' – '.$visit->actionTaken->name : ''),
                    'qty' => 1, 'gross' => (int) $visit->labour_charge, 'hsn' => $sac, 'gst_rate' => $serviceRate];
            }
            foreach ($visit->inventoryUsage as $usage) {
                $lines[] = ['type' => 'part', 'description' => trim(($usage->item?->name ?? 'Spare part').' '.($usage->item?->code ? "({$usage->item->code})" : '')),
                    'qty' => (float) $usage->quantity, 'gross' => (int) $usage->total_price] + $part($usage->item);
            }
            $adjustment = (int) $visit->spare_charge - (int) $visit->inventoryUsage->sum('total_price');
            if ($adjustment !== 0) {
                $lines[] = ['type' => 'part', 'description' => 'Spare charge adjustment', 'qty' => 1, 'gross' => $adjustment, 'hsn' => null, 'gst_rate' => $partRate];
            }
        }

        return $lines;
    }

    public function payLink(Invoice $invoice): string
    {
        return rtrim(config('app.frontend_url'), '/').'/pay/'.$invoice->pay_token;
    }

    public const TEMPLATES = ['classic', 'modern'];

    /** The invoice as a PDF in the company's chosen design (or $template, for a preview). */
    public function pdf(Invoice $invoice, ?string $template = null): \Barryvdh\DomPDF\PDF
    {
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        // Invoices created before GST support: work out the breakup once (an issued plain invoice keeps taxable = total).
        if ($invoice->exists && $invoice->gst_breakup === null && $invoice->taxable_amount === 0 && $invoice->total_amount !== 0) {
            $invoice->is_gst = $this->startsAsGst($tenant, $invoice->job_id ? 'job_bills' : 'walkin_bills');
            $this->applyGst($invoice);
        }
        $invoice->loadMissing([
            'customer', 'branch', 'items.item', 'payments' => fn ($q) => $q->where('status', 'success'),
            'job.customerProduct.product.brand', 'job.customerProduct.brand', 'job.complaintType',
        ]);

        $template = in_array($template, self::TEMPLATES, true) ? $template : $tenant->setting('invoice_template', 'classic');
        $template = in_array($template, self::TEMPLATES, true) ? $template : 'classic';

        $pdf = Pdf::loadView("pdf.invoice-{$template}", $this->pdfData($invoice, $tenant))->setPaper('a4');
        if ($template === 'classic') {
            self::addPageNumbers($pdf);
        }

        return $pdf;
    }

    /** "Page 1 of 2" at the foot of each page; the total is only known once the PDF is laid out. */
    public static function addPageNumbers(\Barryvdh\DomPDF\PDF $pdf): void
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $dompdf->getCanvas()->page_text(524, 812, 'Page {PAGE_NUM} of {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('helvetica'), 8, [0.2, 0.2, 0.2]);
    }

    /** Everything an invoice design prints, worked out once so both designs show identical figures. */
    private function pdfData(Invoice $invoice, Tenant $tenant): array
    {
        $money = fn (int $v) => Money::format($v, $tenant->currency);
        $upi = $invoice->exists ? $this->upi($invoice) : null;
        // Plain (non-GST) invoices go through the same exact split at 0% so the discount is shared out per line too.
        $lines = $invoice->is_gst && $invoice->gst_breakup
            ? $invoice->gst_breakup['lines']
            : Gst::breakup(array_map(fn ($l) => ['gst_rate' => 0] + $l, $this->gstLines($invoice, $tenant)), $invoice->discount_amount)['lines'];
        $discount = array_sum(array_column($lines, 'discount'));
        $logo = null;
        if ($tenant->logo_path && Storage::disk('private')->exists($tenant->logo_path)) {
            $logo = 'data:image/png;base64,'.base64_encode(Storage::disk('private')->get($tenant->logo_path));
        }
        $companyState = IndianStates::code($tenant->gstin) ?? IndianStates::code($tenant->state);
        $customerState = IndianStates::code($invoice->customer?->gstin) ?? IndianStates::code($invoice->customer?->state);

        // Tax totals by rate, for designs that list "CGST @ 9%" line by line.
        $taxRates = [];
        foreach ($invoice->is_gst ? ($invoice->gst_breakup['summary'] ?? []) : [] as $s) {
            if ((float) $s['gst_rate'] <= 0) {
                continue;
            }
            $key = (string) $s['gst_rate'];
            $taxRates[$key] ??= ['rate' => self::percent($s['gst_rate']), 'half' => self::percent($s['gst_rate'] / 2), 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            foreach (['cgst', 'sgst', 'igst'] as $k) {
                $taxRates[$key][$k] += $s[$k];
            }
        }
        ksort($taxRates, SORT_NUMERIC);

        return [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'money' => $money,
            // A plain figure, for designs whose column headings already say "(Rs.)".
            'amount' => fn (int $v) => number_format($v / 100, 2),
            'taxRates' => array_values($taxRates),
            'bank' => array_replace(['account_name' => null, 'bank_name' => null, 'account_no' => null, 'ifsc' => null, 'branch' => null], (array) $tenant->setting('bank')),
            'companyStateName' => $companyState ? IndianStates::name($companyState) : $tenant->state,
            'companyStateCode' => $companyState,
            'customerStateName' => $customerState ? IndianStates::name($customerState) : $invoice->customer?->state,
            'customerStateCode' => $customerState,
            'qty' => fn ($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.'),
            'lines' => $lines,
            'summary' => $invoice->is_gst ? ($invoice->gst_breakup['summary'] ?? []) : [],
            'gross' => array_sum(array_column($lines, 'gross')),
            'discount' => $discount,
            'interState' => $invoice->igst_amount !== 0,
            'tax' => $invoice->cgst_amount + $invoice->sgst_amount + $invoice->igst_amount,
            'words' => Money::inWords($invoice->total_amount, $tenant->currency),
            'title' => $invoice->is_gst ? 'TAX INVOICE' : 'INVOICE',
            'companyState' => $companyState ? IndianStates::name($companyState).' ('.$companyState.')' : $tenant->state,
            'customerState' => $customerState ? IndianStates::name($customerState).' ('.$customerState.')' : $invoice->customer?->state,
            'logo' => $logo,
            'upi' => $upi,
            'upiQr' => $upi ? QrCode::pngDataUri($upi['link'], 4) : null,
            'signature' => $this->signature($invoice),
            'tz' => $tenant->timezone,
        ];
    }

    private static function percent(float|int|string $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');
    }

    /** Latest customer sign-off on the invoice's job, embedded as a data URI. */
    private function signature(Invoice $invoice): ?array
    {
        if (! $invoice->job_id) {
            return null;
        }
        $image = JobImage::where('job_id', $invoice->job_id)->where('type', 'signature')->with('visit')->latest('id')->first();
        if (! $image || ! Storage::disk('private')->exists($image->file_path)) {
            return null;
        }

        return [
            'src' => 'data:image/png;base64,'.base64_encode(Storage::disk('private')->get($image->file_path)),
            'name' => $image->visit?->signer_name,
            'at' => $image->visit?->signed_at ?? $image->created_at,
        ];
    }

    /**
     * "Scan to pay" details for an invoice's balance, from the branch's (or company's) UPI account.
     *
     * @return array{account_id: int, name: string, vpa: string, payee_name: string, amount: int, link: string}|null
     */
    public function upi(Invoice $invoice): ?array
    {
        if ($invoice->balance_amount <= 0) {
            return null;
        }
        $account = UpiAccount::forBranch($invoice->branch_id);
        if (! $account) {
            return null;
        }
        $tenant = Tenant::find($invoice->tenant_id);

        return [
            'account_id' => $account->id,
            'name' => $account->name,
            'vpa' => $account->vpa,
            'payee_name' => $account->payee_name,
            'amount' => $invoice->balance_amount,
            'link' => $account->link($invoice->balance_amount, "Invoice {$invoice->invoice_number}", $tenant?->currency ?? 'INR'),
        ];
    }
}
