<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\JobService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobController extends Controller
{
    public function __construct(private JobService $jobs) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request)
            ->with([
                'customer:id,name,phone,city', 'technician:id,name', 'branch:id,name', 'serviceLocation:id,name',
                'complaintType:id,name', 'customerProduct:id,product_id,serial_no', 'customerProduct.product:id,model_name,brand_id',
                'customerProduct.product.brand:id,name',
            ]);

        $sort = $request->string('sort')->toString();
        match ($sort) {
            'scheduled' => $query->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at'),
            'oldest' => $query->oldest('id'),
            'priority' => $query->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")->latest('id'),
            default => $query->latest('id'),
        };

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    /** Status counters (technician dashboard FR-3.1 and board header). */
    public function counts(Request $request): JsonResponse
    {
        $counts = $this->filtered($request, ignoreStatus: true)
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return $this->ok(collect(JobStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]));
    }

    /** Calendar view: jobs scheduled within a date range. */
    public function calendar(Request $request): JsonResponse
    {
        $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $tz = app(TenantContext::class)->tenant()->timezone;
        $from = $request->date('from', null, $tz)->startOfDay()->utc();
        $to = $request->date('to', null, $tz)->endOfDay()->utc();
        abort_if($from->diffInDays($to) > 62, 422, 'Range too large.');

        $jobs = $this->filtered($request)
            ->whereBetween('scheduled_at', [$from, $to])
            ->with(['customer:id,name', 'technician:id,name'])
            ->orderBy('scheduled_at')
            ->limit(1000)
            ->get(['id', 'crm_call_id', 'status', 'priority', 'scheduled_at', 'customer_id', 'assigned_technician_id']);

        return $this->ok($jobs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->assertProductBelongsToCustomer($data);
        $autoAssign = (bool) ($data['auto_assign'] ?? false);
        unset($data['auto_assign']);
        if ($autoAssign) {
            unset($data['assigned_technician_id']);
        }
        $job = $this->jobs->create($data, $request->user());

        $message = "Job {$job->crm_call_id} created.";
        $meta = [];
        if ($autoAssign) {
            $meta['auto_assign'] = $result = $this->jobs->autoAssign($job, $request->user());
            $message = $result['assigned']
                ? "Job {$job->crm_call_id} created and auto-assigned to {$result['technician']['name']}."
                : "Job {$job->crm_call_id} created but not assigned: {$result['reason']}";
        }

        return $this->ok($job->fresh()->load(['customer:id,name,phone', 'technician:id,name', 'serviceLocation:id,name']), $message, 201, $meta);
    }

    /** FR-5.3 full job context. */
    public function show(Request $request, int $id): JsonResponse
    {
        $job = ServiceJob::with([
            'customer', 'branch:id,name', 'serviceLocation:id,name', 'complaintType:id,name', 'complaintSummary:id,name',
            'customerProduct.product.brand:id,name', 'customerProduct.product.category:id,name', 'customerProduct.dealer:id,name',
            'technician:id,name,phone', 'creator:id,name',
            'visits.technician:id,name', 'visits.actionTaken:id,name', 'visits.assistedStaff:id,name',
            'visits.inventoryUsage.item:id,code,name,type,unit_of_measure',
            'images', 'voiceNotes.uploader:id,name', 'statusHistory.user:id,name',
            'invoice.payments.collector:id,name', 'review', 'parent:id,crm_call_id,status', 'followUps:id,parent_job_id,crm_call_id,status,scheduled_at',
        ])->findOrFail($id);

        $this->authorizeView($request->user(), $job);
        $job->customerProduct?->setAttribute('under_warranty', $job->customerProduct->isUnderWarranty());

        return $this->ok($job);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $job = ServiceJob::findOrFail($id);
        if ($job->status->isFinal()) {
            throw ValidationException::withMessages(['status' => 'Closed jobs cannot be edited.']);
        }
        $data = $request->validate(collect($this->rules())->except(['customer_id', 'assigned_technician_id', 'crm_call_id', 'auto_assign'])
            ->map(fn ($rules) => array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $rules))->all());
        $data['customer_id'] = $job->customer_id;
        $this->assertProductBelongsToCustomer($data);
        unset($data['customer_id']);
        $job->update($data);

        return $this->ok($job, 'Job updated.');
    }

    /** Assign an existing job to the least-loaded technician for its location. */
    public function autoAssign(Request $request, int $id): JsonResponse
    {
        $job = ServiceJob::findOrFail($id);
        $result = $this->jobs->autoAssign($job, $request->user());
        if (! $result['assigned']) {
            throw ValidationException::withMessages(['technician' => $result['reason']]);
        }

        return $this->ok($job->fresh()->load('technician:id,name'), "Auto-assigned to {$result['technician']['name']} ({$result['open_jobs']} open jobs).", 200, ['auto_assign' => $result]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'technician_id' => ['required', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $job = $this->jobs->assign(ServiceJob::findOrFail($id), $data['technician_id'], $data['scheduled_at'] ?? null, $request->user());

        return $this->ok($job->load('technician:id,name'), 'Technician assigned.');
    }

    public function reschedule(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $job = $this->jobs->reschedule(ServiceJob::findOrFail($id), $data['scheduled_at'], $data['reason'] ?? null, $request->user());

        return $this->ok($job, 'Job rescheduled.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $job = $this->jobs->cancel(ServiceJob::findOrFail($id), $data['reason'], $request->user());

        return $this->ok($job, 'Job cancelled.');
    }

    /** FR-5.6 reminder / 2nd service call. */
    public function followUp(Request $request, int $id): JsonResponse
    {
        $parent = ServiceJob::findOrFail($id);
        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'complaint_details' => ['nullable', 'string', 'max:2000'],
            'assigned_technician_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
        ]);
        $job = $this->jobs->createFollowUp($parent, $data, $request->user());

        return $this->created($job, "Follow-up {$job->crm_call_id} created.");
    }

    private function filtered(Request $request, bool $ignoreStatus = false): Builder
    {
        $user = $request->user();
        $tz = app(TenantContext::class)->tenant()->timezone;

        return ServiceJob::query()
            ->when($user->isTechnician(), fn ($q) => $q->where('assigned_technician_id', $user->id))
            ->when(! $ignoreStatus && $request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('technician_id'), fn ($q) => $request->input('technician_id') === 'unassigned'
                ? $q->whereNull('assigned_technician_id')
                : $q->where('assigned_technician_id', $request->integer('technician_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('service_location_id'), fn ($q) => $q->where('service_location_id', $request->integer('service_location_id')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('complaint_type_id'), fn ($q) => $q->where('complaint_type_id', $request->integer('complaint_type_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from', null, $tz)->startOfDay()->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to', null, $tz)->endOfDay()->utc()))
            ->when($request->boolean('today'), fn ($q) => $q->whereBetween('scheduled_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()]))
            // Same rule as the dashboard's "overdue" count: still open, visit date before today.
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', ['open', 'pending'])->where('scheduled_at', '<', now($tz)->startOfDay()->utc()))
            ->search($request->string('search')->toString());
    }

    private function authorizeView(User $user, ServiceJob $job): void
    {
        if ($user->isTechnician() && $job->assigned_technician_id !== $user->id) {
            abort(404);
        }
    }

    private function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $exists = fn (string $t) => Rule::exists($t, 'id')->where('tenant_id', $tenantId);

        return [
            'customer_id' => ['required', 'integer', $exists('customers')->whereNull('deleted_at')],
            'customer_product_id' => ['nullable', 'integer', $exists('customer_products')],
            'crm_call_id' => ['nullable', 'string', 'max:50', Rule::unique('service_jobs', 'crm_call_id')->where('tenant_id', $tenantId)],
            'branch_id' => ['nullable', 'integer', $exists('branches')],
            'complaint_type_id' => ['nullable', 'integer', $exists('complaint_types')],
            'complaint_summary_id' => ['nullable', 'integer', $exists('complaint_summaries')],
            'complaint_details' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high'])],
            'call_type' => ['required', Rule::in(['crm_call', 'walk_in', 'referral'])],
            'scheduled_at' => ['nullable', 'date'],
            'assigned_technician_id' => ['nullable', 'integer'],
            'service_location_id' => ['nullable', 'integer', $exists('service_locations')],
            'auto_assign' => ['sometimes', 'boolean'],
        ];
    }

    private function assertProductBelongsToCustomer(array $data): void
    {
        if (! empty($data['customer_product_id'])
            && ! CustomerProduct::where('customer_id', $data['customer_id'])->whereKey($data['customer_product_id'])->exists()) {
            throw ValidationException::withMessages(['customer_product_id' => 'This product does not belong to the selected customer.']);
        }
    }
}
