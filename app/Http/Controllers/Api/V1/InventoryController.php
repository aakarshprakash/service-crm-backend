<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\Supplier;
use App\Services\InventoryService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Spares & consumables (§5.7).
 */
class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function items(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: null;
        $items = InventoryItem::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->withSum(['stock as stock_total' => fn ($q) => $q->when($branchId, fn ($b) => $b->where('branch_id', $branchId))], 'quantity_available')
            ->when($request->boolean('low_stock'), fn ($q) => $q->where('reorder_level', '>', 0)
                ->whereHas('stock', fn ($s) => $s->whereColumn('inventory_stock.quantity_available', '<=', 'inventory_items.reorder_level')
                    ->when($branchId, fn ($b) => $b->where('branch_id', $branchId))))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($items);
    }

    public function categories(): JsonResponse
    {
        return $this->ok(InventoryItem::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'));
    }

    public function storeItem(Request $request): JsonResponse
    {
        $item = InventoryItem::create($request->validate($this->itemRules()));

        return $this->created($item, 'Item created.');
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);
        $item->update($request->validate($this->itemRules($item)));

        return $this->ok($item, 'Item updated.');
    }

    /** FR-7.5 availability check (by id or code) across branches. */
    public function availability(string $item): JsonResponse
    {
        $record = InventoryItem::where(fn ($q) => $q->where('code', $item)
            ->when(ctype_digit($item), fn ($w) => $w->orWhere('id', (int) $item)))->firstOrFail();

        return $this->ok(['item' => $record, 'branches' => $this->inventory->availability($record)]);
    }

    /** Branch stock levels with low-stock flag and valuation. */
    public function stock(Request $request): JsonResponse
    {
        $rows = InventoryStock::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_stock.item_id')
            ->join('branches', 'branches.id', '=', 'inventory_stock.branch_id')
            ->when($request->filled('branch_id'), fn ($q) => $q->where('inventory_stock.branch_id', $request->integer('branch_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('inventory_items.type', $request->string('type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('inventory_items.code', 'like', $like)->orWhere('inventory_items.name', 'like', $like));
            })
            ->when($request->boolean('low_stock'), fn ($q) => $q->where('inventory_items.reorder_level', '>', 0)
                ->whereColumn('inventory_stock.quantity_available', '<=', 'inventory_items.reorder_level'))
            ->select([
                'inventory_stock.id', 'inventory_stock.branch_id', 'inventory_stock.item_id', 'inventory_stock.quantity_available',
                'inventory_items.code', 'inventory_items.name', 'inventory_items.type',
                'inventory_items.category', 'inventory_items.unit_of_measure', 'inventory_items.reorder_level', 'inventory_items.unit_price',
                'branches.name as branch_name',
                DB::raw('(inventory_items.reorder_level > 0 AND inventory_stock.quantity_available <= inventory_items.reorder_level) as is_low'),
            ])
            // Purchase cost and valuation are office information, not for technicians.
            ->unless($request->user()->isTechnician(), fn ($q) => $q->addSelect([
                'inventory_stock.avg_unit_cost',
                DB::raw('ROUND(inventory_stock.quantity_available * inventory_stock.avg_unit_cost) as stock_value'),
            ]))
            ->orderBy('inventory_items.name')
            ->paginate($this->perPage($request));

        return $this->paginated($rows);
    }

    public function stockIn(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'invoice_ref' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,3'],
            'lines.*.unit_cost' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $items = InventoryItem::whereIn('id', collect($data['lines'])->pluck('item_id'))->get()->keyBy('id');
            foreach ($data['lines'] as $line) {
                $this->inventory->stockIn($data['branch_id'], $items[$line['item_id']], (float) $line['quantity'], (int) $line['unit_cost'], [
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'invoice_ref' => $data['invoice_ref'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        return $this->created(null, 'Stock received.');
    }

    public function transfer(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $this->inventory->transfer($data['from_branch_id'], $data['to_branch_id'], InventoryItem::findOrFail($data['item_id']),
            (float) $data['quantity'], $data['remarks'] ?? null, $request->user()->id);

        return $this->created(null, 'Stock transferred.');
    }

    /** FR-7.8 manual adjustment – reason mandatory. */
    public function adjust(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'not_in:0', 'decimal:0,3'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $this->inventory->adjust($data['branch_id'], InventoryItem::findOrFail($data['item_id']), (float) $data['quantity'], $data['reason'], $request->user()->id);

        return $this->created(null, 'Stock adjusted.');
    }

    public function transactions(Request $request): JsonResponse
    {
        $tz = app(TenantContext::class)->tenant()->timezone;
        $rows = InventoryTransaction::with(['item:id,code,name,unit_of_measure', 'branch:id,name', 'supplier:id,name', 'creator:id,name'])
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', $request->integer('item_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from', null, $tz)->startOfDay()->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to', null, $tz)->endOfDay()->utc()))
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($rows);
    }

    // ---- Suppliers (FR-7.10) --------------------------------------------

    public function suppliers(Request $request): JsonResponse
    {
        $rows = Supplier::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', $this->like($request->string('search'))))
            ->orderBy('name')->paginate($this->perPage($request, 50));

        return $this->paginated($rows);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        return $this->created(Supplier::create($request->validate($this->supplierRules())), 'Supplier added.');
    }

    public function updateSupplier(Request $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update($request->validate($this->supplierRules(true)));

        return $this->ok($supplier, 'Supplier updated.');
    }

    private function itemRules(?InventoryItem $item = null): array
    {
        $tenantId = app(TenantContext::class)->id();
        $req = $item ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-\/]+$/', Rule::unique('inventory_items', 'code')->where('tenant_id', $tenantId)->ignore($item?->id)],
            'name' => [$req, 'string', 'max:150'],
            'type' => [$req, Rule::in(['spare', 'consumable'])],
            'category' => ['nullable', 'string', 'max:100'],
            'unit_of_measure' => [$req, Rule::in(['nos', 'ml', 'ltr', 'gram', 'kg', 'metre'])],
            'unit_price' => [$req, 'integer', 'min:0', 'max:100000000'],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'is_active' => ['boolean'],
        ];
    }

    private function supplierRules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:150'],
            'contact' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'gstin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Z]{15}$/'],
            'is_active' => ['boolean'],
        ];
    }
}
