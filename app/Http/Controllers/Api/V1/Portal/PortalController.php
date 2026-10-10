<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\ComplaintType;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Review;
use App\Models\ServiceJob;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-service portal (§5.11). Every query is limited to the signed-in
 * customer's own records.
 */
class PortalController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $customer = $request->user()->customer;
        $jobs = ServiceJob::where('customer_id', $customer->id);

        return $this->ok([
            'customer' => $customer->only(['id', 'name', 'phone', 'email', 'address', 'city', 'pincode']),
            'open_jobs' => (clone $jobs)->whereIn('status', ['open', 'in_progress', 'pending'])->count(),
            'completed_jobs' => (clone $jobs)->where('status', 'completed')->count(),
            'outstanding' => (int) Invoice::where('customer_id', $customer->id)->sum('balance_amount'),
            'products' => CustomerProduct::where('customer_id', $customer->id)->with('product.brand:id,name')->get(),
            'online_payments' => app(TenantContext::class)->tenant()->onlinePaymentsEnabled(),
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $jobs = ServiceJob::where('customer_id', $request->user()->customer_id)
            ->with(['technician:id,name', 'complaintType:id,name', 'customerProduct.product:id,model_name', 'review:id,job_id,rating'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->latest('id')->paginate($this->perPage($request, 20));

        return $this->paginated($jobs, fn ($j) => $this->publicJob($j));
    }

    /** FR-11.3 real-time status (client polls). */
    public function job(Request $request, int $id): JsonResponse
    {
        $job = ServiceJob::where('customer_id', $request->user()->customer_id)
            ->with(['technician:id,name,phone', 'complaintType:id,name', 'customerProduct.product.brand:id,name',
                'statusHistory', 'invoice', 'review', 'visits.actionTaken:id,name'])
            ->findOrFail($id);

        $data = $this->publicJob($job) + [
            'timeline' => $job->statusHistory->map(fn ($h) => ['status' => $h->status, 'at' => $h->changed_at, 'remarks' => $this->publicRemark($h->remarks)])->values(),
            'visits' => $job->visits->map(fn ($v) => [
                'date' => $v->visit_date, 'status' => $v->status, 'action_taken' => $v->actionTaken?->name,
                'summary' => $v->service_summary, 'total_charge' => $v->total_charge,
            ]),
            'invoice' => $job->invoice?->only(['id', 'invoice_number', 'total_amount', 'paid_amount', 'balance_amount', 'payment_status', 'generated_at']),
            'review' => $job->review?->only(['rating', 'comment', 'created_at']),
            'technician_phone' => in_array($job->status, [JobStatus::Open, JobStatus::InProgress, JobStatus::Pending], true) ? $job->technician?->phone : null,
        ];

        return $this->ok($data);
    }

    /** FR-11.2 raise a new complaint. */
    public function raiseComplaint(Request $request, JobService $jobs): JsonResponse
    {
        $user = $request->user();
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'customer_product_id' => ['nullable', 'integer', Rule::exists('customer_products', 'id')->where('customer_id', $user->customer_id)],
            'complaint_type_id' => ['nullable', 'integer', Rule::exists('complaint_types', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'complaint_details' => ['required', 'string', 'min:10', 'max:2000'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:'.now(app(TenantContext::class)->tenant()->timezone)->toDateString(), 'before:+60 days'],
        ]);

        $open = ServiceJob::where('customer_id', $user->customer_id)->whereIn('status', ['open', 'in_progress', 'pending'])
            ->when($data['customer_product_id'] ?? null, fn ($q, $p) => $q->where('customer_product_id', $p))->count();
        if ($open >= 5) {
            throw ValidationException::withMessages(['complaint_details' => 'You already have several open requests. Our team will contact you shortly.']);
        }

        $job = $jobs->create([
            'customer_id' => $user->customer_id,
            'customer_product_id' => $data['customer_product_id'] ?? null,
            'complaint_type_id' => $data['complaint_type_id'] ?? null,
            'complaint_details' => $data['complaint_details'],
            'scheduled_at' => isset($data['preferred_date']) ? $data['preferred_date'].' 10:00:00' : null,
            'priority' => 'medium',
            'call_type' => 'portal',
        ], null);
        // Nobody at the office logged this one: tell the people who dispatch jobs.
        app(NotificationService::class)->notifyRoles(['admin', 'coordinator'], 'new_complaint', 'New service request',
            "{$job->customer?->name} raised {$job->crm_call_id} from the customer portal.", ['job_id' => $job->id]);

        return $this->created($this->publicJob($job->load('complaintType:id,name')), "Request {$job->crm_call_id} registered. We will contact you shortly.");
    }

    public function complaintTypes(): JsonResponse
    {
        return $this->ok(ComplaintType::where('is_active', true)->orderBy('name')->get(['id', 'name']));
    }

    public function invoices(Request $request): JsonResponse
    {
        $rows = Invoice::where('customer_id', $request->user()->customer_id)->with('job:id,crm_call_id')
            ->latest('id')->paginate($this->perPage($request, 20));

        return $this->paginated($rows, fn ($i) => $i->only(['id', 'invoice_number', 'total_amount', 'paid_amount', 'balance_amount', 'payment_status', 'generated_at']) + ['call_id' => $i->job?->crm_call_id]);
    }

    public function invoicePdf(Request $request, int $id, InvoiceService $invoices): Response
    {
        $invoice = Invoice::where('customer_id', $request->user()->customer_id)->findOrFail($id);

        return $invoices->pdf($invoice)->download($invoice->invoice_number.'.pdf');
    }

    /** FR-11.5 online payment for a pending invoice. */
    public function payInvoice(Request $request, int $id, PaymentService $payments): JsonResponse
    {
        $invoice = Invoice::where('customer_id', $request->user()->customer_id)->findOrFail($id);

        return $this->ok($payments->createGatewayOrder($invoice));
    }

    public function confirmPayment(Request $request, int $id, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:100'],
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'max:200'],
        ]);
        $invoice = Invoice::where('customer_id', $request->user()->customer_id)->findOrFail($id);
        $payment = $payments->confirmCheckout($invoice, $data);

        return $this->ok(['status' => $payment->status, 'receipt_number' => $payment->receipt_number], 'Payment successful. Thank you!');
    }

    /** FR-11.6 rate the service after completion. */
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $job = ServiceJob::where('customer_id', $request->user()->customer_id)->findOrFail($id);
        if ($job->status !== JobStatus::Completed) {
            throw ValidationException::withMessages(['rating' => 'You can rate a service once it is completed.']);
        }
        if (Review::where('job_id', $job->id)->exists()) {
            throw ValidationException::withMessages(['rating' => 'You have already rated this service.']);
        }
        $review = Review::create($data + [
            'job_id' => $job->id,
            'customer_id' => $job->customer_id,
            'technician_id' => $job->assigned_technician_id,
        ]);

        return $this->created($review, 'Thank you for your feedback!');
    }

    private function publicJob(ServiceJob $job): array
    {
        return [
            'id' => $job->id,
            'crm_call_id' => $job->crm_call_id,
            'status' => $job->status->value,
            'complaint_type' => $job->complaintType?->name,
            'complaint_details' => $job->complaint_details,
            'product' => $job->customerProduct?->product?->model_name,
            'technician' => $job->technician?->name,
            'scheduled_at' => $job->scheduled_at,
            'created_at' => $job->created_at,
            'completed_at' => $job->completed_at,
            'rated' => $job->relationLoaded('review') ? (bool) $job->review : null,
        ];
    }

    /** Internal remarks (e.g. reassignment names) are not shown to customers verbatim. */
    private function publicRemark(?string $remarks): ?string
    {
        if (! $remarks) {
            return null;
        }

        return match (true) {
            str_starts_with($remarks, 'Assigned') || str_starts_with($remarks, 'Reassigned') => 'Technician assigned',
            str_starts_with($remarks, 'Rescheduled') => 'Visit rescheduled',
            str_starts_with($remarks, 'Service started') => 'Technician started the service',
            str_starts_with($remarks, 'Visit closed') => 'Visit completed',
            default => null,
        };
    }
}
