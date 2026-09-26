<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionTakenOption;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ComplaintSummary;
use App\Models\ComplaintType;
use App\Models\Dealer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant-configurable master data (§5.14) behind one controller:
 * /master/{type} where type ∈ brands, categories, products, dealers, complaint-types,
 * complaint-summaries, action-taken-options, branches.
 */
class MasterDataController extends Controller
{
    private const TYPES = [
        'brands' => Brand::class,
        'categories' => ProductCategory::class,
        'products' => Product::class,
        'dealers' => Dealer::class,
        'complaint-types' => ComplaintType::class,
        'complaint-summaries' => ComplaintSummary::class,
        'action-taken-options' => ActionTakenOption::class,
        'branches' => Branch::class,
    ];

    /** All active lookups in one call for forms (cached per request by the client). */
    public function lookups(): JsonResponse
    {
        $active = fn (string $class, array $cols = ['id', 'name']) => $class::where('is_active', true)->orderBy($cols[1] ?? 'name')->get($cols);

        return $this->ok([
            'branches' => $active(Branch::class),
            'brands' => $active(Brand::class),
            'categories' => $active(ProductCategory::class),
            'products' => Product::where('is_active', true)->with(['brand:id,name', 'category:id,name'])->orderBy('model_name')->get(['id', 'brand_id', 'category_id', 'model_name']),
            'dealers' => $active(Dealer::class),
            'complaint_types' => $active(ComplaintType::class),
            'complaint_summaries' => ComplaintSummary::where('is_active', true)->orderBy('name')->get(['id', 'name', 'complaint_type_id']),
            'action_taken_options' => $active(ActionTakenOption::class),
        ]);
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $class = $this->model($type);
        $query = $class::query()
            ->when($request->filled('search'), function ($q) use ($request, $type) {
                $q->where($type === 'products' ? 'model_name' : 'name', 'like', $this->like($request->string('search')));
            })
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')));

        if ($type === 'products') {
            $query->with(['brand:id,name', 'category:id,name'])->orderBy('model_name');
        } elseif ($type === 'complaint-summaries') {
            $query->with('complaintType:id,name')->orderBy('name');
        } else {
            $query->orderBy('name');
        }

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $class = $this->model($type);
        $record = $class::create($request->validate($this->rules($type)));

        return $this->created($record);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $class = $this->model($type);
        $record = $class::findOrFail($id);
        $record->update($request->validate($this->rules($type, $record)));

        return $this->ok($record, 'Saved.');
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $record = $this->model($type)::findOrFail($id);
        try {
            $record->delete();
        } catch (QueryException) {
            // In use by jobs / stock: keep history intact, just deactivate.
            $record->update(['is_active' => false]);

            return $this->ok($record, 'This record is in use, so it was deactivated instead of deleted.');
        }

        return $this->ok(null, 'Deleted.');
    }

    /** @return class-string<Model> */
    private function model(string $type): string
    {
        return self::TYPES[$type] ?? abort(404);
    }

    private function rules(string $type, ?Model $record = null): array
    {
        $tenantId = app(TenantContext::class)->id();
        $table = (new (self::TYPES[$type]))->getTable();
        $exists = fn (string $t) => Rule::exists($t, 'id')->where('tenant_id', $tenantId);
        $uniqueName = Rule::unique($table, 'name')->where('tenant_id', $tenantId)->ignore($record?->id);
        $sometimes = $record ? 'sometimes' : 'required';

        return match ($type) {
            'products' => [
                'model_name' => [$sometimes, 'string', 'max:150'],
                'brand_id' => ['nullable', 'integer', $exists('brands')],
                'category_id' => ['nullable', 'integer', $exists('product_categories')],
                'is_active' => ['boolean'],
            ],
            'dealers' => [
                'name' => [$sometimes, 'string', 'max:150'],
                'contact' => ['nullable', 'string', 'max:150'],
                'phone' => ['nullable', 'string', 'max:20'],
                'is_active' => ['boolean'],
            ],
            'complaint-summaries' => [
                'name' => [$sometimes, 'string', 'max:200'],
                'complaint_type_id' => ['nullable', 'integer', $exists('complaint_types')],
                'is_active' => ['boolean'],
            ],
            'branches' => [
                'name' => [$sometimes, 'string', 'max:150'],
                'code' => ['nullable', 'string', 'max:20'],
                'address' => ['nullable', 'string', 'max:500'],
                'city' => ['nullable', 'string', 'max:100'],
                'state' => ['nullable', 'string', 'max:100'],
                'pincode' => ['nullable', 'string', 'max:12'],
                'phone' => ['nullable', 'string', 'max:20'],
                'is_active' => ['boolean'],
            ],
            default => [
                'name' => [$sometimes, 'string', 'max:150', $uniqueName],
                'is_active' => ['boolean'],
            ],
        };
    }
}
