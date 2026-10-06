<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoices,
        private PaymentService $payments,
    ) {}

    /** FR-10.6 invoice list with filters and totals. */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request)
            ->with(['customer:id,name,phone', 'job:id,crm_call_id,assigned_technician_id', 'job.technician:id,name', 'branch:id,name'])
            ->when($request->filled('payment_status'), fn ($q) => $q->whereIn('payment_status', explode(',', $request->string('payment_status'))))
            ->when($request->boolean('credit'), fn ($q) => $q->where('is_credit', true))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('technician_id'), fn ($q) => $q->whereHas('job', fn ($j) => $j->where('assigned_technician_id', $request->integer('technician_id'))))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('generated_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('generated_at', '<=', $request->date('to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('invoice_number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                    ->orWhereHas('job', fn ($j) => $j->where('crm_call_id', 'like', $like)));
            });

        $totals = (clone $query)->toBase()->reorder()
            ->selectRaw('COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(balance_amount),0) as balance')
            ->first();

        return $this->paginated($query->latest('id')->paginate($this->perPage($request)), null, [
            'totals' => ['total' => (int) $totals->total, 'paid' => (int) $totals->paid, 'balance' => (int) $totals->balance],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $invoice = $this->scoped($request)->with([
            'customer', 'branch:id,name', 'payments.collector:id,name', 'creator:id,name', 'items.item:id,code,name,unit_of_measure',
            'job:id,crm_call_id,status,customer_product_id,assigned_technician_id', 'job.technician:id,name',
            'job.visits' => fn ($q) => $q->where('status', '!=', 'in_progress'),
            'job.visits.actionTaken:id,name', 'job.visits.technician:id,name', 'job.visits.inventoryUsage.item:id,code,name,unit_of_measure',
        ])->findOrFail($id);

        $data = $invoice->toArray();
        $data['upi'] = $this->invoices->upi($invoice);
        if ($request->user()->can('payments.record')) {
            $data['pay_link'] = $this->invoices->payLink($invoice);
        }

        return $this->ok($data);
    }

    public function pdf(Request $request, int $id): Response
    {
        $invoice = $this->scoped($request)->findOrFail($id);

        return $this->invoices->pdf($invoice)->download($invoice->invoice_number.'.pdf');
    }

    /**
     * Office / accountant records an offline payment (cash, UPI, cheque, bank transfer)
     * against an invoice – e.g. a credit customer settling later.
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::in(['cash', 'upi', 'cheque', 'bank_transfer'])],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $invoice = Invoice::findOrFail($id);
        $payment = $this->payments->recordOffline($invoice, $data['amount'], $data['method'], $data + ['collected_by' => null]);

        return $this->created($payment, "Payment recorded. Receipt {$payment->receipt_number}.");
    }

    /** Technician collects a pending balance later (e.g. revisit) against their own job's invoice. */
    public function collect(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::in(['cash', 'upi', 'cheque', 'bank_transfer'])],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ]);
        $invoice = $this->scoped($request)->findOrFail($id);
        $payment = $this->payments->recordOffline($invoice, $data['amount'], $data['method'], $data + [
            'collected_by' => $request->user()->id, 'remarks' => 'Collected by service agent',
        ]);

        return $this->created($payment, "Payment recorded. Receipt {$payment->receipt_number}.");
    }

    /** Send the payment link / balance reminder by SMS / WhatsApp. */
    public function sendReminder(int $id, NotificationService $notifications): JsonResponse
    {
        $invoice = Invoice::with('job')->findOrFail($id);
        abort_if($invoice->balance_amount <= 0, 422, 'This invoice is fully paid.');
        $tenant = app(TenantContext::class)->tenant();
        $notifications->notifyInvoiceCustomer($invoice, 'payment_link', [
            'invoice_number' => $invoice->invoice_number,
            'balance' => Money::format($invoice->balance_amount, $tenant->currency),
            'pay_link' => $tenant->onlinePaymentsEnabled() ? $this->invoices->payLink($invoice) : 'Please pay our service agent or visit our office.',
        ]);

        return $this->ok(null, 'Reminder queued.');
    }

    public function payments(Request $request): JsonResponse
    {
        $tz = app(TenantContext::class)->tenant()->timezone;
        $query = Payment::query()
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('collected_by'), fn ($q) => $q->where('collected_by', $request->integer('collected_by')))
            // Dates are the company's local days.
            ->when($request->filled('from'), fn ($q) => $q->where('paid_at', '>=', CarbonImmutable::parse($request->input('from'), $tz)->startOfDay()->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('paid_at', '<=', CarbonImmutable::parse($request->input('to'), $tz)->endOfDay()->utc()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('receipt_number', 'like', $like)->orWhere('reference_no', 'like', $like)
                    ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))));
            });
        $byMethod = (clone $query)->where('status', 'success')->selectRaw('method, SUM(amount) as t')->groupBy('method')->pluck('t', 'method')->map(fn ($v) => (int) $v);
        $rows = $query->with(['invoice:id,invoice_number,customer_id', 'invoice.customer:id,name', 'collector:id,name'])
            ->latest('paid_at')->latest('id')->paginate($this->perPage($request));

        return $this->paginated($rows, null, ['total_amount' => (int) $byMethod->sum(), 'by_method' => $byMethod]);
    }

    /** Technicians only see invoices for jobs assigned to them. */
    private function scoped(Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user();

        return Invoice::query()->when($user->isTechnician(),
            fn ($q) => $q->whereHas('job', fn ($j) => $j->where('assigned_technician_id', $user->id)));
    }
}
