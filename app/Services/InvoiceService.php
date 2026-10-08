<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\JobImage;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Models\UpiAccount;
use App\Support\Money;
use App\Support\QrCode;
use App\Support\Sequence;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                    'invoice_number' => Sequence::formatted($job->tenant_id, 'invoice', $tenant->setting('invoice_prefix', 'INV')),
                    'pay_token' => Str::random(48),
                    'generated_at' => now(),
                ]);
            }

            $invoice->fill([
                'total_service_charge' => $service,
                'total_spare_charge' => $spare,
                'total_amount' => $total,
                'is_credit' => $visits->contains('payment_method', 'credit'),
            ]);
            $invoice->save();
            $invoice->refreshPaymentTotals();

            return $invoice;
        });
    }

    public function payLink(Invoice $invoice): string
    {
        return rtrim(config('app.frontend_url'), '/').'/pay/'.$invoice->pay_token;
    }

    public function pdf(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing([
            'customer', 'branch', 'items.item', 'payments' => fn ($q) => $q->where('status', 'success'),
            'job.visits.actionTaken', 'job.visits.technician:id,name', 'job.visits.inventoryUsage.item',
            'job.customerProduct.product.brand', 'job.complaintType',
        ]);
        $tenant = Tenant::findOrFail($invoice->tenant_id);
        $money = fn (int $v) => Money::format($v, $tenant->currency);
        $upi = $this->upi($invoice);
        $upiQr = $upi ? QrCode::pngDataUri($upi['link'], 4) : null;
        $signature = $this->signature($invoice);

        return Pdf::loadView('pdf.invoice', compact('invoice', 'tenant', 'money', 'upi', 'upiQr', 'signature'))->setPaper('a4');
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
