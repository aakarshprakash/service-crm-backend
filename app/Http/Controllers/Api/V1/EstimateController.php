<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Services\EstimateService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Estimates (quotations) made by the office: create, send, and record the customer's answer. */
class EstimateController extends Controller
{
    public function __construct(private EstimateService $estimates) {}

    public function index(Request $request): JsonResponse
    {
        $tz = app(TenantContext::class)->tenant()->timezone;
        $today = now($tz)->toDateString();
        $query = $this->filtered($request)
            ->when($request->filled('status'), fn ($q) => match ($request->input('status')) {
                // Expired is not stored: an open estimate past its last valid day.
                'expired' => $q->whereIn('status', ['draft', 'sent'])->where('valid_until', '<', $today),
                'open' => $q->whereIn('status', ['draft', 'sent'])->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $today)),
                default => $q->where('status', $request->input('status')),
            })
            ->with(['customer:id,name,phone', 'job:id,crm_call_id', 'creator:id,name'])
            ->latest('estimate_date')->latest('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    /** How many estimates are in each state, and what the open and approved ones are worth. */
    public function summary(Request $request): JsonResponse
    {
        $tz = app(TenantContext::class)->tenant()->timezone;
        $today = now($tz)->toDateString();
        $rows = $this->filtered($request)->selectRaw('status, COUNT(*) as n, SUM(total_amount) as total, SUM(CASE WHEN valid_until < ? THEN 1 ELSE 0 END) as past, SUM(CASE WHEN valid_until < ? THEN total_amount ELSE 0 END) as past_total', [$today, $today])
            ->groupBy('status')->get()->keyBy('status');
        $n = fn (string $s, string $col = 'n') => (int) ($rows[$s]->{$col} ?? 0);
        $expired = $n('draft', 'past') + $n('sent', 'past');

        return $this->ok([
            'draft' => $n('draft') - $n('draft', 'past'),
            'sent' => $n('sent') - $n('sent', 'past'),
            'approved' => $n('approved'),
            'rejected' => $n('rejected'),
            'expired' => $expired,
            'open_total' => $n('draft', 'total') + $n('sent', 'total') - $n('draft', 'past_total') - $n('sent', 'past_total'),
            'approved_total' => $n('approved', 'total'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok($this->present(Estimate::findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $estimate = $this->estimates->create($request->validate($this->rules(), self::MESSAGES), $request->user());

        return $this->created($this->present($estimate), "Estimate {$estimate->estimate_number} created.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $estimate = Estimate::findOrFail($id);
        $rules = collect($this->rules())->except('customer_id')->all();
        $rules['items'][0] = 'sometimes';
        $estimate = $this->estimates->update($estimate, $request->validate($rules, self::MESSAGES));

        return $this->ok($this->present($estimate), 'Estimate saved.');
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $estimate = $this->estimates->setStatus(Estimate::findOrFail($id), $data['status'], $data['note'] ?? null, $request->user());

        return $this->ok($this->present($estimate), match ($data['status']) {
            'sent' => 'Marked as sent to the customer.',
            'approved' => 'Estimate approved.',
            'rejected' => 'Estimate marked rejected.',
            default => 'Estimate reopened as a draft.',
        });
    }

    /** A copy to start a revised or similar estimate from. */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $source = Estimate::with('items')->findOrFail($id);
        $copy = $this->estimates->create([
            'customer_id' => $source->customer_id, 'job_id' => $source->job_id, 'customer_product_id' => $source->customer_product_id,
            'branch_id' => $source->branch_id, 'subject' => $source->subject, 'notes' => $source->notes, 'terms' => $source->terms,
            'is_gst' => $source->is_gst, 'discount' => $source->discount_amount,
            'items' => $source->items->map->only(['type', 'item_id', 'description', 'quantity', 'unit_price'])->all(),
        ], $request->user());

        return $this->created($this->present($copy), "Copied as {$copy->estimate_number}.");
    }

    public function destroy(int $id): JsonResponse
    {
        $estimate = Estimate::findOrFail($id);
        if ($estimate->status === 'approved') {
            throw ValidationException::withMessages(['status' => 'An approved estimate is kept on record. Reopen it first if it was approved by mistake.']);
        }
        $estimate->delete();

        return $this->ok(null, "Estimate {$estimate->estimate_number} deleted.");
    }

    public function pdf(int $id): Response
    {
        $estimate = Estimate::findOrFail($id);

        return $this->estimates->pdf($estimate)->stream("{$estimate->estimate_number}.pdf");
    }

    /** The PDF behind the link shared with the customer: no sign-in, only the random token. */
    public function publicPdf(string $token): Response
    {
        // Found by its token alone, whatever tenant this request may already carry.
        $estimate = Estimate::withoutGlobalScopes()->where('share_token', $token)->firstOrFail();

        return app(TenantContext::class)->runAs($estimate->tenant_id, fn () => $this->estimates->pdf($estimate)->stream("{$estimate->estimate_number}.pdf"));
    }

    private function present(Estimate $estimate): array
    {
        $estimate->load([
            'customer:id,name,phone,alt_phone,address,city,state,pincode,gstin', 'job:id,crm_call_id,status', 'branch:id,name',
            'customerProduct:id,product_id,model_no,brand_id,category_id,serial_no', 'customerProduct.brand:id,name', 'customerProduct.category:id,name', 'customerProduct.product:id,model_name,brand_id',
            'customerProduct.product.brand:id,name', 'items.item:id,code,name,unit_of_measure', 'creator:id,name', 'decider:id,name',
        ]);

        return $estimate->toArray() + ['share_url' => $this->estimates->shareUrl($estimate)];
    }

    private function filtered(Request $request)
    {
        return Estimate::query()
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('job_id'), fn ($q) => $q->where('job_id', $request->integer('job_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('estimate_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('estimate_date', '<=', $request->date('to')))
            ->search($request->string('search')->toString());
    }

    private const MESSAGES = [
        'items.required' => 'Add at least one service or part.',
        'items.min' => 'Add at least one service or part.',
        'items.*.unit_price.required_if' => 'Enter the service charge.',
        'valid_until.after_or_equal' => 'The last valid day can’t be before the estimate date.',
    ];

    private function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $exists = fn (string $t) => Rule::exists($t, 'id')->where('tenant_id', $tenantId);

        return [
            'customer_id' => ['required', 'integer', $exists('customers')->whereNull('deleted_at')],
            'job_id' => ['nullable', 'integer', $exists('service_jobs')],
            'customer_product_id' => ['nullable', 'integer', $exists('customer_products')],
            'branch_id' => ['nullable', 'integer', $exists('branches')],
            'estimate_date' => ['sometimes', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:estimate_date'],
            'subject' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:3000'],
            'is_gst' => ['sometimes', 'boolean'],
            'discount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.type' => ['required', Rule::in(['service', 'part'])],
            'items.*.item_id' => ['nullable', 'integer', $exists('inventory_items')],
            'items.*.description' => ['nullable', 'string', 'max:300'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'],
            'items.*.unit_price' => ['required_if:items.*.type,service', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
