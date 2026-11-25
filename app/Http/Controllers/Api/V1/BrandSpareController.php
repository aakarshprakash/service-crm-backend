<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BrandSpare;
use App\Services\Spares\SpareExporter;
use App\Services\Spares\SpareImportService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Brand spares: parts the brand billed to us, and getting the warranty ones back to the brand.
 * Most work is done on many lines at once (bulk), because the brand bills and collects in batches.
 */
class BrandSpareController extends Controller
{
    /** Lines changed in one bulk action at most. */
    private const BULK_LIMIT = 2000;

    private const MESSAGES = [
        'billing_doc.required' => 'Enter the billing document number from the brand\'s bill.',
        'billing_date.required' => 'Enter the billing date.',
        'material_code.required' => 'Enter the material (part) code.',
    ];

    public function __construct(private SpareImportService $imports) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request)
            ->with(['job:id,crm_call_id,status,customer_id', 'job.customer:id,name,phone'])
            ->orderByDesc('billing_date')->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    /**
     * Lines and money in each stage, and the lines that need a look: sent back but still on the
     * brand's pending list. Follows the search and dates, not the stage being shown.
     */
    public function summary(Request $request): JsonResponse
    {
        $scope = fn () => $this->filtered($request, withStage: false);
        $stages = $scope()->selectRaw('stage, COUNT(*) as n, COALESCE(SUM(value), 0) as total')->groupBy('stage')->get()->keyBy('stage');
        $mismatch = $scope()->where('company_pending', true)->where('stage', 'sent')->selectRaw('COUNT(*) as n, COALESCE(SUM(value), 0) as total')->first();

        return $this->ok([
            'stages' => collect(BrandSpare::STAGES)->mapWithKeys(fn ($s) => [$s => ['count' => (int) ($stages[$s]->n ?? 0), 'value' => (int) ($stages[$s]->total ?? 0)]]),
            'mismatch' => ['count' => (int) $mismatch->n, 'value' => (int) $mismatch->total],
            'company_pending' => $scope()->where('company_pending', true)->count(),
            'company_checked_on' => BrandSpare::max('company_checked_on'),
            'last_billing_date' => BrandSpare::max('billing_date'),
            'couriers' => BrandSpare::whereNotNull('courier')->groupBy('courier')->orderByRaw('COUNT(*) DESC')->limit(8)->pluck('courier'),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(BrandSpare::with(['job:id,crm_call_id,status,customer_id', 'job.customer:id,name,phone'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), self::MESSAGES);
        $spare = new BrandSpare($data + ['brand_id' => $this->imports->lloydId(), 'created_by' => $request->user()->id]);
        // The same key an upload of this bill would give, so uploading it later does not add the line again.
        $n = 1;
        do {
            $spare->import_key = BrandSpare::importKey($spare->billing_doc, strtoupper($spare->material_code), (float) ($spare->quantity ?? 1), (int) ($spare->value ?? 0), $n++);
        } while (BrandSpare::where('import_key', $spare->import_key)->exists());
        $spare->material_code = strtoupper($spare->material_code);
        $spare->save();

        return $this->created($spare->load(['job:id,crm_call_id,status,customer_id', 'job.customer:id,name,phone']), 'Part added.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $spare = BrandSpare::findOrFail($id);
        // Required fields may be left out, but not emptied.
        $rules = collect($this->rules())->map(fn ($r) => ['sometimes', ...$r])->all();
        $spare->fill($request->validate($rules, self::MESSAGES));
        if (! $spare->ow_billed) {
            $spare->ow_billed_on = null;
        }
        $spare->save();

        return $this->ok($spare->load(['job:id,crm_call_id,status,customer_id', 'job.customer:id,name,phone']), 'Saved.');
    }

    public function destroy(int $id): JsonResponse
    {
        BrandSpare::findOrFail($id)->delete();

        return $this->ok(null, 'Part line deleted.');
    }

    /**
     * One action on many lines: the ticked ids, or every line matching the list's filters.
     * Returning a part only makes sense for warranty lines; an unsorted line becomes warranty,
     * out-of-warranty and stock lines are skipped and counted.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['coverage', 'inspect', 'send', 'fresh', 'ow_billed', 'company_cleared', 'delete'])],
            'ids' => ['required_without:filter', 'array', 'max:'.self::BULK_LIMIT],
            'ids.*' => ['integer'],
            'filter' => ['required_without:ids', 'array'],
            'filter.all' => ['nullable', 'boolean'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.stage' => ['nullable', Rule::in(BrandSpare::STAGES)],
            'filter.coverage' => ['nullable', Rule::in(BrandSpare::COVERAGES)],
            'filter.attention' => ['nullable', Rule::in(['mismatch', 'company_pending'])],
            'filter.from' => ['nullable', 'date'],
            'filter.to' => ['nullable', 'date'],
            'coverage' => ['required_if:action,coverage', 'nullable', Rule::in(BrandSpare::COVERAGES)],
            'inspection_no' => ['required_if:action,inspect', 'nullable', 'string', 'max:120'],
            'rma_no' => ['nullable', 'string', 'max:120'],
            'lr_date' => ['required_if:action,send', 'nullable', 'date'],
            'lr_number' => ['nullable', 'string', 'max:60'],
            'courier' => ['required_if:action,send', 'nullable', 'string', 'max:60'],
            'ow_billed_on' => ['required_if:action,ow_billed', 'nullable', 'date'],
            'ow_bill_no' => ['nullable', 'string', 'max:30'],
        ], [
            'inspection_no.required_if' => 'Enter the inspection number.',
            'lr_date.required_if' => 'Enter the date the parts were handed to the courier.',
            'courier.required_if' => 'Enter the courier.',
            'ow_billed_on.required_if' => 'Enter the date the brand billed it as OW.',
        ]);

        // A filter must say what it matches: "all" on purpose, or at least one known filter.
        $filter = isset($data['ids']) ? null : array_filter($data['filter'] ?? [], fn ($v) => $v !== null && $v !== '');
        if ($filter !== null && ! array_intersect_key($filter, array_flip(['all', 'search', 'stage', 'coverage', 'attention', 'from', 'to']))) {
            throw ValidationException::withMessages(['filter' => 'Choose the parts to change first.']);
        }

        $query = $filter === null ? BrandSpare::whereIn('id', $data['ids']) : $this->filtered(new Request($filter));
        $spares = $query->limit(self::BULK_LIMIT + 1)->get();
        if ($spares->count() > self::BULK_LIMIT) {
            throw ValidationException::withMessages([$filter === null ? 'ids' : 'filter' => 'That is more than '.number_format(self::BULK_LIMIT).' parts. Narrow the list with a stage, date or search first.']);
        }

        if ($data['action'] === 'delete') {
            DB::transaction(fn () => $spares->each->delete());

            return $this->ok(['updated' => $spares->count(), 'skipped' => 0], $spares->count() === 1 ? 'Part line deleted.' : "{$spares->count()} part lines deleted.");
        }

        $returning = in_array($data['action'], ['inspect', 'send', 'fresh', 'ow_billed'], true);
        $updated = 0;
        $skipped = 0;
        DB::transaction(function () use ($spares, $data, $returning, &$updated, &$skipped) {
            foreach ($spares as $spare) {
                if ($returning && $this->notReturnable($spare, $data['action'])) {
                    $skipped++;

                    continue;
                }
                $spare->fill($this->changes($data));
                if ($returning) {
                    $spare->coverage = 'warranty';
                }
                $spare->save();
                $updated++;
            }
        });

        $message = ($updated === 1 ? '1 part updated.' : "{$updated} parts updated.")
            .($skipped ? " {$skipped} skipped: out of warranty, stock, or already past this step." : '');

        return $this->ok(['updated' => $updated, 'skipped' => $skipped], $message);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'], 'dry_run' => ['boolean']], [
            'file.required' => 'Choose the Excel file to upload.',
            'file.mimes' => 'Upload an Excel file (.xlsx, .xls or .csv).',
            'file.max' => 'The file is larger than 10 MB.',
        ]);
        $dryRun = $request->boolean('dry_run');
        $result = $this->imports->import($request->file('file'), $request->user(), $dryRun);

        return $this->ok($result, $dryRun ? '' : ($result['new'] === 1 ? '1 part line added.' : "{$result['new']} part lines added."));
    }

    public function companyPending(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'], 'dry_run' => ['boolean']], [
            'file.required' => 'Choose the brand\'s pending list to upload.',
            'file.mimes' => 'Upload an Excel file (.xlsx, .xls or .csv).',
        ]);
        $dryRun = $request->boolean('dry_run');
        $result = $this->imports->companyPending($request->file('file'), $dryRun);

        return $this->ok($result, $dryRun ? '' : "Brand's pending list updated: {$result['matched']} pending, {$result['cleared']} cleared.");
    }

    public function export(Request $request, SpareExporter $exporter): BinaryFileResponse
    {
        $path = $exporter->write($this->filtered($request));
        $tz = app(TenantContext::class)->tenant()->timezone;

        return response()->download($path, 'brand-spares-'.now($tz)->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** The list's filters: stage, coverage, needs-a-look, search and billing dates. */
    private function filtered(Request $request, bool $withStage = true): Builder
    {
        $search = trim((string) $request->input('search'));
        $attention = $withStage ? $request->input('attention') : null;

        return BrandSpare::query()
            ->when($withStage && $request->filled('stage'), fn ($q) => $q->where('stage', $request->input('stage')))
            ->when($request->filled('coverage'), fn ($q) => $q->where('coverage', $request->input('coverage')))
            ->when($attention === 'mismatch', fn ($q) => $q->where('company_pending', true)->where('stage', 'sent'))
            ->when($attention === 'company_pending', fn ($q) => $q->where('company_pending', true))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('billing_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('billing_date', '<=', $request->input('to')))
            ->when($search !== '', function ($q) use ($search) {
                $like = $this->like($search);
                $q->where(fn ($w) => $w->where('billing_doc', 'like', $like)->orWhere('material_code', 'like', $like)
                    ->orWhere('description', 'like', $like)->orWhere('ticket_no', 'like', $like)->orWhere('customer_name', 'like', $like)
                    ->orWhere('inspection_no', 'like', $like)->orWhere('rma_no', 'like', $like)->orWhere('lr_number', 'like', $like)
                    ->orWhere('po_number', 'like', $like)->orWhere('delivery_no', 'like', $like));
            });
    }

    /**
     * Out of warranty and stock parts are never returned; a part already sent or closed is not sent
     * or inspected again by a bulk action (its own editor can still correct it).
     */
    private function notReturnable(BrandSpare $spare, string $action): bool
    {
        if (in_array($spare->coverage, ['out_of_warranty', 'stock'], true) || $spare->ow_billed) {
            return true;
        }

        return $action !== 'ow_billed' && in_array($spare->stage, ['sent', 'closed'], true);
    }

    /** The fields a bulk action writes. */
    private function changes(array $data): array
    {
        $optional = fn (string $k) => ($data[$k] ?? '') !== '' ? [$k => $data[$k]] : [];

        return match ($data['action']) {
            'coverage' => ['coverage' => $data['coverage']],
            'inspect' => ['inspection_no' => $data['inspection_no'], ...$optional('rma_no')],
            'send' => ['lr_date' => $data['lr_date'], 'courier' => $data['courier'], ...$optional('lr_number'), ...$optional('rma_no')],
            'fresh' => ['return_type' => 'fresh'],
            'ow_billed' => ['ow_billed' => true, 'ow_billed_on' => $data['ow_billed_on'], ...$optional('ow_bill_no')],
            'company_cleared' => ['company_pending' => false],
        };
    }

    private function rules(): array
    {
        return [
            'billing_doc' => ['required', 'string', 'max:30'],
            'billing_date' => ['required', 'date'],
            'material_code' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:200'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'value' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'delivery_no' => ['nullable', 'string', 'max:30'],
            'po_number' => ['nullable', 'string', 'max:40'],
            'ticket_no' => ['nullable', 'string', 'max:40'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'coverage' => ['nullable', Rule::in(BrandSpare::COVERAGES)],
            'return_type' => [Rule::in(BrandSpare::RETURN_TYPES)],
            'inspection_no' => ['nullable', 'string', 'max:120'],
            'rma_no' => ['nullable', 'string', 'max:120'],
            'lr_number' => ['nullable', 'string', 'max:60'],
            'lr_date' => ['nullable', 'date'],
            'courier' => ['nullable', 'string', 'max:60'],
            'ow_billed' => ['boolean'],
            'ow_billed_on' => ['nullable', 'date'],
            'ow_bill_no' => ['nullable', 'string', 'max:30'],
            'company_pending' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
