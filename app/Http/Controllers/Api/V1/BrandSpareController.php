<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BrandSpare;
use App\Models\Customer;
use App\Services\Spares\SpareActivity;
use App\Services\Spares\SpareImportService;
use App\Services\Spares\SpareQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SAPRE spare parts: the register, the dashboard figures, one part's page and its history.
 * Bulk changes are in SpareBulkController; Excel in and out in SpareFileController.
 */
class BrandSpareController extends Controller
{
    private const MESSAGES = [
        'billing_doc.required' => 'Enter the billing document number from the brand\'s bill.',
        'billing_date.required' => 'Enter the billing date.',
        'material_code.required' => 'Enter the material (part) code.',
    ];

    /** The job a part was used on: the one whose External CRM call ID is the part's EX CRM ID. */
    private const LINKS = ['job:id,crm_call_id,status,customer_id', 'job.customer:id,name,phone'];

    public function __construct(private SpareImportService $imports, private SpareActivity $activity) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(SpareQuery::rules());
        $query = SpareQuery::apply($filters)->with(self::LINKS)->orderByDesc('billing_date')->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    /**
     * The dashboard: lines and money in each status and each classification, and the parts sent
     * back that Lloyd still lists as pending. Follows the search and dates, not the status shown.
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate(SpareQuery::rules());
        $scope = fn () => SpareQuery::apply($filters, withStatus: false);
        $pair = fn ($row) => ['count' => (int) ($row->n ?? 0), 'value' => (int) ($row->total ?? 0)];

        $statuses = $scope()->selectRaw('status, COUNT(*) as n, COALESCE(SUM(value), 0) as total')->groupBy('status')->get()->keyBy('status');
        $classes = $scope()->selectRaw("CASE WHEN coverage IS NULL THEN 'none' WHEN ow_billed = 1 THEN 'charged' ELSE coverage END as k, COUNT(*) as n, COALESCE(SUM(value), 0) as total")
            ->groupBy('k')->get()->keyBy('k');
        $mismatch = $scope()->where('company_pending', true)->where('status', 'pending_return')->selectRaw('COUNT(*) as n, COALESCE(SUM(value), 0) as total')->first();
        $total = $scope()->selectRaw('COUNT(*) as n, COALESCE(SUM(value), 0) as total')->first();
        // Inspected parts still without an RMA / challan: step 2 (Inspection & RMA); the rest of pending_dispatch is step 3.
        $rmaMissing = $scope()->where('status', 'pending_dispatch')->whereNull('rma_no')->selectRaw('COUNT(*) as n, COALESCE(SUM(value), 0) as total')->first();

        $byStatus = collect(BrandSpare::STATUSES)->mapWithKeys(fn ($s) => [$s => $pair($statuses[$s] ?? null)]);
        $sum = fn (array $keys) => [
            'count' => $byStatus->only($keys)->sum('count'),
            'value' => $byStatus->only($keys)->sum('value'),
        ];

        return $this->ok([
            'total' => $pair($total),
            'pending' => $sum(BrandSpare::PENDING),
            'completed' => $sum(BrandSpare::DONE),
            'by_status' => $byStatus,
            'by_class' => collect(['warranty', 'out_of_warranty', 'charged', 'stock', 'none'])->mapWithKeys(fn ($k) => [$k => $pair($classes[$k] ?? null)]),
            'mismatch' => $pair($mismatch),
            'rma_missing' => $pair($rmaMissing),
            'company_checked_on' => BrandSpare::max('company_checked_on'),
            'last_billing_date' => BrandSpare::max('billing_date'),
            'couriers' => BrandSpare::whereNotNull('courier')->groupBy('courier')->orderByRaw('COUNT(*) DESC')->limit(8)->pluck('courier'),
        ]);
    }

    /** The latest changes across all parts, for the dashboard. */
    public function recent(): JsonResponse
    {
        return $this->ok($this->activity->recent());
    }

    public function show(int $id): JsonResponse
    {
        $spare = BrandSpare::with([...self::LINKS, 'creator:id,name'])->findOrFail($id);

        return $this->ok($spare->toArray() + ['customer_by_crm' => $spare->job ? null : $this->customerByCrm($spare)]);
    }

    /** One part's history, in words. */
    public function activity(int $id): JsonResponse
    {
        return $this->ok($this->activity->forSpare(BrandSpare::with('creator:id,name')->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), self::MESSAGES);
        $spare = new BrandSpare($data + ['brand_id' => $this->imports->lloydId(), 'created_by' => $request->user()->id]);
        $spare->material_code = strtoupper($spare->material_code);
        // The same key an upload of this bill would give, so uploading it later updates this line.
        $n = 1;
        do {
            $spare->import_key = BrandSpare::importKey($spare->billing_doc, $spare->material_code, (float) ($spare->quantity ?? 1), (int) ($spare->value ?? 0), $n++);
        } while (BrandSpare::where('import_key', $spare->import_key)->exists());
        $this->tidy($spare);
        $spare->save();

        return $this->created($spare->load(self::LINKS), 'Part added.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $spare = BrandSpare::findOrFail($id);
        // Required fields may be left out, but not emptied.
        $rules = collect($this->rules())->map(fn ($r) => ['sometimes', ...$r])->all();
        $spare->fill($request->validate($rules, self::MESSAGES));
        $this->tidy($spare);
        $spare->save();

        return $this->ok($spare->load(self::LINKS), 'Saved.');
    }

    public function destroy(int $id): JsonResponse
    {
        BrandSpare::findOrFail($id)->delete();

        return $this->ok(null, 'Part line deleted.');
    }

    /** Keeps the OW stage consistent with the warranty choice. */
    private function tidy(BrandSpare $spare): void
    {
        $owPath = BrandSpare::onOwPath($spare->getAttributes());
        if ($owPath && ! $spare->ow_stage) {
            $spare->ow_stage = $spare->ow_billed ? 'billed' : 'marked';
        }
        if (! $owPath) {
            $spare->ow_stage = null;
        }
    }

    /** A customer whose CRM ID is the part's EX CRM ID, when no job has it as its call ID. */
    private function customerByCrm(BrandSpare $spare): ?array
    {
        if (! $spare->ticket_no) {
            return null;
        }

        return Customer::where('crm_id', $spare->ticket_no)->first(['id', 'name', 'phone'])?->toArray();
    }

    private function rules(): array
    {
        $date = ['nullable', 'date'];

        return [
            'billing_doc' => ['required', 'string', 'max:30'],
            'billing_date' => ['required', 'date'],
            'material_code' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:200'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'value' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'delivery_no' => ['nullable', 'string', 'max:30'],
            'po_number' => ['nullable', 'string', 'max:40'],
            'received_on' => $date,
            'ticket_no' => ['nullable', 'string', 'max:40'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'coverage' => ['nullable', Rule::in(BrandSpare::COVERAGES)],
            'return_type' => [Rule::in(BrandSpare::RETURN_TYPES)],
            'inspection_no' => ['nullable', 'string', 'max:120'],
            'inspection_on' => $date,
            'rma_no' => ['nullable', 'string', 'max:120'],
            'rma_on' => $date,
            'lr_number' => ['nullable', 'string', 'max:60'],
            'lr_date' => $date,
            'courier' => ['nullable', 'string', 'max:60'],
            'return_received_on' => $date,
            'replacement_doc' => ['nullable', 'string', 'max:30'],
            'replacement_material' => ['nullable', 'string', 'max:40'],
            'replacement_on' => $date,
            'ow_billed' => ['boolean'],
            'ow_stage' => ['nullable', Rule::in(BrandSpare::OW_STAGES)],
            'ow_billed_on' => $date,
            'ow_bill_no' => ['nullable', 'string', 'max:30'],
            'ow_paid_on' => $date,
            'ow_payment_ref' => ['nullable', 'string', 'max:60'],
            'company_pending' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
