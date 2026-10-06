<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActionTakenOption;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ComplaintSummary;
use App\Models\ComplaintType;
use App\Models\Dealer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ServiceJob;
use App\Models\ServiceLocation;
use App\Models\UpiAccount;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant-configurable master data (§5.14) behind one controller:
 * /master/{type} where type ∈ brands, categories, products, dealers, complaint-types,
 * complaint-summaries, action-taken-options, branches, service-locations.
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
        'service-locations' => ServiceLocation::class,
        'expense-categories' => ExpenseCategory::class,
        'upi-accounts' => UpiAccount::class,
        'leave-types' => LeaveType::class,
        'holidays' => Holiday::class,
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
            'service_locations' => ServiceLocation::where('is_active', true)->orderBy('name')->get(['id', 'name', 'city', 'pincodes']),
            'expense_categories' => $active(ExpenseCategory::class),
            'upi_accounts' => UpiAccount::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'vpa', 'payee_name', 'branch_id', 'is_default']),
            'leave_types' => LeaveType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'annual_quota', 'is_paid']),
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
        } elseif ($type === 'service-locations') {
            $query->with('technicians:id,name')->withCount('technicians')->orderBy('name');
        } elseif ($type === 'upi-accounts') {
            $query->with('branch:id,name')->orderByDesc('is_default')->orderBy('name');
        } elseif ($type === 'holidays') {
            $query->when($request->filled('year'), fn ($q) => $q->whereYear('date', $request->integer('year')))->orderBy('date');
        } else {
            $query->orderBy('name');
        }

        return $this->paginated($query->paginate($this->perPage($request, 50)));
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $class = $this->model($type);
        $data = $request->validate($this->rules($type));
        $technicianIds = $this->pullTechnicians($data);
        $record = $class::create($data);
        if ($technicianIds !== null) {
            $record->technicians()->sync($technicianIds);
            $record->load('technicians:id,name')->loadCount('technicians');
        }

        return $this->created($record);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $class = $this->model($type);
        $record = $class::findOrFail($id);
        $data = $request->validate($this->rules($type, $record));
        $technicianIds = $this->pullTechnicians($data);
        $record->update($data);
        if ($technicianIds !== null) {
            $record->technicians()->sync($technicianIds);
            $record->load('technicians:id,name')->loadCount('technicians');
        }

        return $this->ok($record, 'Saved.');
    }

    public function destroy(string $type, int $id): JsonResponse
    {
        $record = $this->model($type)::findOrFail($id);
        // Jobs keep their location history (the FK would otherwise just null it out).
        if ($type === 'service-locations' && ServiceJob::where('service_location_id', $record->id)->exists()) {
            $record->update(['is_active' => false]);

            return $this->ok($record, 'This location is used by jobs, so it was deactivated instead of deleted.');
        }
        if ($type === 'leave-types' && LeaveRequest::where('leave_type_id', $record->id)->exists()) {
            $record->update(['is_active' => false]);

            return $this->ok($record, 'This leave type has been used, so it was deactivated instead of deleted.');
        }
        if ($type === 'expense-categories' && Expense::where('expense_category_id', $record->id)->exists()) {
            $record->update(['is_active' => false]);

            return $this->ok($record, 'This category has expenses, so it was deactivated instead of deleted.');
        }
        try {
            $record->delete();
        } catch (QueryException) {
            // In use by jobs / stock: keep history intact, just deactivate.
            $record->update(['is_active' => false]);

            return $this->ok($record, 'This record is in use, so it was deactivated instead of deleted.');
        }

        return $this->ok(null, 'Deleted.');
    }

    /** Technician links are a relation, not a column: take them out of the validated data. */
    private function pullTechnicians(array &$data): ?array
    {
        if (! array_key_exists('technician_ids', $data)) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', $data['technician_ids'] ?? [])));
        unset($data['technician_ids']);

        return $ids;
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
                'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:geofence_radius'],
                'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:geofence_radius'],
                'geofence_radius' => ['nullable', 'integer', 'min:25', 'max:50000'],
                'is_active' => ['boolean'],
            ],
            'upi-accounts' => [
                'name' => [$sometimes, 'string', 'max:100', $uniqueName],
                // name@bank — what UPI apps call the "UPI ID" / VPA.
                'vpa' => [$sometimes, 'string', 'max:100', 'regex:/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z][a-zA-Z0-9.\-]{1,63}$/'],
                'payee_name' => [$sometimes, 'string', 'max:100'],
                'branch_id' => ['nullable', 'integer', $exists('branches')],
                'is_default' => ['boolean'],
                'is_active' => ['boolean'],
            ],
            'leave-types' => [
                'name' => [$sometimes, 'string', 'max:100', $uniqueName],
                'code' => ['nullable', 'string', 'max:10'],
                'annual_quota' => ['nullable', 'numeric', 'min:0', 'max:365'],
                'is_paid' => ['boolean'],
                'is_active' => ['boolean'],
            ],
            'holidays' => [
                'date' => [$sometimes, 'date', Rule::unique('holidays', 'date')->where('tenant_id', $tenantId)->ignore($record?->id)],
                'name' => [$sometimes, 'string', 'max:150'],
                'is_active' => ['boolean'],
            ],
            'service-locations' => [
                'name' => [$sometimes, 'string', 'max:150', $uniqueName],
                'code' => ['nullable', 'string', 'max:20'],
                'city' => ['nullable', 'string', 'max:100'],
                'pincodes' => ['nullable', 'string', 'max:2000'],
                'is_active' => ['boolean'],
                'technician_ids' => ['sometimes', 'array', 'max:500'],
                'technician_ids.*' => ['integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('role', 'technician')],
            ],
            default => [
                'name' => [$sometimes, 'string', 'max:150', $uniqueName],
                'is_active' => ['boolean'],
            ],
        };
    }
}
