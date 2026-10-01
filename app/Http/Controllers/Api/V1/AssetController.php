<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Sequence;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Asset register: company tools / vehicles / devices issued to technicians and returned.
 * An asset is held by at most one person at a time (status "assigned").
 */
class AssetController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $assets = Asset::query()
            ->with(['holder:id,name', 'branch:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('asset_code', 'like', $like)
                    ->orWhere('serial_no', 'like', $like)->orWhere('brand', 'like', $like)->orWhere('model', 'like', $like));
            })
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->orderBy('asset_code')
            ->paginate($this->perPage($request));

        $counts = Asset::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return $this->paginated($assets, null, ['counts' => collect(Asset::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])]);
    }

    public function show(int $id): JsonResponse
    {
        $asset = Asset::with([
            'holder:id,name,phone', 'branch:id,name',
            'assignments.user:id,name', 'assignments.issuer:id,name', 'assignments.receiver:id,name',
        ])->findOrFail($id);

        return $this->ok($asset);
    }

    /** Assets the signed-in person currently holds (technician app "My assets"). */
    public function mine(Request $request): JsonResponse
    {
        $assets = Asset::where('assigned_to', $request->user()->id)
            ->with('currentAssignment:id,asset_id,issued_at,issue_condition,issue_notes')
            ->orderBy('name')->get(['id', 'asset_code', 'name', 'category', 'brand', 'model', 'serial_no', 'condition']);

        return $this->ok($assets);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $tenantId = app(TenantContext::class)->id();
        $asset = DB::transaction(function () use ($data, $tenantId) {
            $data['asset_code'] = ($data['asset_code'] ?? null) ?: Sequence::formatted($tenantId, 'asset', 'AST', 4);

            return Asset::create($data + ['status' => 'available']);
        });

        return $this->created($asset->load('branch:id,name'), "Asset {$asset->asset_code} added.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $data = $request->validate($this->rules($asset));
        if (array_key_exists('asset_code', $data) && ! $data['asset_code']) {
            unset($data['asset_code']); // keep the existing code rather than blanking it
        }
        $asset->update($data);

        return $this->ok($asset->load(['holder:id,name', 'branch:id,name']), 'Asset updated.');
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->where('status', 'active')->where('role', Role::Technician->value)],
            'condition' => ['nullable', Rule::in(Asset::CONDITIONS)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['user_id.exists' => 'Choose an active technician.']);

        $asset = DB::transaction(function () use ($id, $data, $request) {
            $asset = Asset::lockForUpdate()->findOrFail($id);
            if ($asset->status !== 'available') {
                throw ValidationException::withMessages(['status' => 'Only an available asset can be issued. This one is '.str_replace('_', ' ', $asset->status).'.']);
            }
            $condition = $data['condition'] ?? $asset->condition;
            AssetAssignment::create([
                'asset_id' => $asset->id, 'user_id' => $data['user_id'], 'issued_by' => $request->user()->id, 'issued_at' => now(),
                'issue_condition' => $condition, 'issue_notes' => $data['notes'] ?? null,
            ]);
            $asset->update(['status' => 'assigned', 'assigned_to' => $data['user_id'], 'condition' => $condition]);

            return $asset;
        });

        $tech = User::inTenant()->find($data['user_id']);
        DB::afterCommit(fn () => $this->notifications->notifyUser($tech, 'asset_issued', 'Asset issued to you',
            "{$asset->name} ({$asset->asset_code}) has been issued to you.", ['asset_id' => $asset->id]));

        return $this->ok($asset->load(['holder:id,name', 'branch:id,name']), "Issued to {$tech->name}.");
    }

    public function return(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'condition' => ['required', Rule::in(Asset::CONDITIONS)],
            'status' => ['nullable', Rule::in(['available', 'under_repair', 'lost'])],
            'notes' => ['nullable', 'string', 'max:500', 'required_if:status,lost'],
        ], ['notes.required_if' => 'Say what happened to the asset.']);

        $asset = DB::transaction(function () use ($id, $data, $request) {
            $asset = Asset::lockForUpdate()->findOrFail($id);
            $open = AssetAssignment::where('asset_id', $asset->id)->whereNull('returned_at')->latest('issued_at')->lockForUpdate()->first();
            if ($asset->status !== 'assigned' || ! $open) {
                throw ValidationException::withMessages(['status' => 'This asset is not issued to anyone.']);
            }
            $open->update([
                'returned_at' => now(), 'received_by' => $request->user()->id,
                'return_condition' => $data['condition'], 'return_notes' => $data['notes'] ?? null,
            ]);
            $asset->update(['status' => $data['status'] ?? 'available', 'assigned_to' => null, 'condition' => $data['condition']]);

            return $asset;
        });

        return $this->ok($asset->load('branch:id,name'), $asset->status === 'lost' ? 'Marked as lost.' : 'Asset returned.');
    }

    /** Repair / lost / retired / back in service, for an asset nobody holds. */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['available', 'under_repair', 'lost', 'retired'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $asset = Asset::findOrFail($id);
        if ($asset->status === 'assigned') {
            throw ValidationException::withMessages(['status' => 'Take the asset back from the technician first.']);
        }
        $notes = trim(($asset->notes ? $asset->notes."\n" : '').(! empty($data['notes']) ? now()->format('d M Y').': '.$data['notes'] : ''));
        $asset->update(['status' => $data['status'], 'notes' => $notes ?: null]);

        return $this->ok($asset->load(['holder:id,name', 'branch:id,name']), 'Status updated.');
    }

    private function rules(?Asset $asset = null): array
    {
        $tenantId = app(TenantContext::class)->id();
        $req = $asset ? 'sometimes' : 'required';

        return [
            'asset_code' => ['nullable', 'string', 'max:30', Rule::unique('assets', 'asset_code')->where('tenant_id', $tenantId)->ignore($asset?->id)],
            'name' => [$req, 'string', 'max:150'],
            'category' => [$req, Rule::in(Asset::CATEGORIES)],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_cost' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'warranty_expiry' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'condition' => ['sometimes', Rule::in(Asset::CONDITIONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
