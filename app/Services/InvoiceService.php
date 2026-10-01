<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\Sequence;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
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

        return Pdf::loadView('pdf.invoice', compact('invoice', 'tenant', 'money'))->setPaper('a4');
    }
}
