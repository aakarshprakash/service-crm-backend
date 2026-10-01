<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PunchLog;
use App\Models\User;
use App\Notifications\UserInvitation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::inTenant()
            ->where('role', '!=', Role::Customer->value)
            ->with(['branch:id,name', 'serviceLocations:id,name'])
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($users);
    }

    /** Lightweight list for dropdowns (technicians, assisted staff). */
    public function options(Request $request): JsonResponse
    {
        $users = User::inTenant()->where('status', 'active')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->where('role', '!=', Role::Customer->value)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'branch_id', 'punch_status', 'phone']);

        return $this->ok($users);
    }

    public function show(int $id): JsonResponse
    {
        return $this->ok(User::inTenant()->with(['branch:id,name', 'serviceLocations:id,name'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $locations = $this->pullLocations($data);
        $tenant = app(TenantContext::class)->tenant();
        $this->enforcePlanLimits($data['role']);

        $user = new User($data);
        $user->tenant_id = $tenant->id;
        $user->status = ! empty($data['password']) ? 'active' : 'invited';
        $user->save();
        if ($locations !== null) {
            $user->serviceLocations()->sync($locations);
        }

        if ($user->status === 'invited') {
            $this->sendInvite($user);
        }

        return $this->created($user->load(['branch:id,name', 'serviceLocations:id,name']), $user->status === 'invited'
            ? 'User created. An invitation email has been sent.'
            : 'User created.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::inTenant()->where('role', '!=', Role::Customer->value)->findOrFail($id);
        $data = $this->validated($request, $user);
        $locations = $this->pullLocations($data);

        if ($user->id === $request->user()->id && isset($data['role']) && $data['role'] !== $user->role->value) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }
        if (isset($data['role']) && $data['role'] !== $user->role->value) {
            $this->enforcePlanLimits($data['role']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);
        if ($locations !== null) {
            $user->serviceLocations()->sync($locations);
        }

        return $this->ok($user->load(['branch:id,name', 'serviceLocations:id,name']), 'User updated.');
    }

    /** FR-2.5: deactivate without deleting history; revokes all sessions / tokens. */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $user = User::inTenant()->findOrFail($id);
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['status' => 'You cannot deactivate your own account.']);
        }
        if ($data['status'] === 'active') {
            $this->enforcePlanLimits($user->role->value);
        }
        $user->update(['status' => $data['status']]);
        if ($data['status'] === 'inactive') {
            $user->tokens()->delete();
        }

        return $this->ok($user, $data['status'] === 'active' ? 'User activated.' : 'User deactivated.');
    }

    public function resendInvite(int $id): JsonResponse
    {
        $user = User::inTenant()->where('status', 'invited')->findOrFail($id);
        $this->sendInvite($user);

        return $this->ok(null, 'Invitation sent.');
    }

    /** FR-2.4 technician punch in / out. */
    public function punch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $user = $request->user();
        if ($user->punch_status === $data['type']) {
            throw ValidationException::withMessages(['type' => "You are already punched {$data['type']}."]);
        }
        PunchLog::create(['user_id' => $user->id] + $data);
        $user->forceFill(['punch_status' => $data['type'], 'punched_at' => now()])->save();

        return $this->ok(['punch_status' => $user->punch_status, 'punched_at' => $user->punched_at],
            $data['type'] === 'in' ? 'Punched in. Have a great day!' : 'Punched out.');
    }

    public function punchLogs(Request $request): JsonResponse
    {
        $logs = PunchLog::with('user:id,name')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('created_at', $request->date('date')))
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($logs);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $tenantId = app(TenantContext::class)->id();

        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:100'],
            'email' => [$user ? 'sometimes' : 'required', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 \-]{7,20}$/',
                Rule::unique('users', 'phone')->where('tenant_id', $tenantId)->ignore($user?->id)],
            'role' => [$user ? 'sometimes' : 'required', Rule::in(Role::staffRoles())],
            'branch_id' => ['nullable', 'integer', Rule::exists(Branch::class, 'id')->where('tenant_id', $tenantId)],
            'password' => ['nullable', 'string', PasswordRule::defaults()],
            'service_location_ids' => ['sometimes', 'array', 'max:200'],
            'service_location_ids.*' => ['integer', Rule::exists('service_locations', 'id')->where('tenant_id', $tenantId)],
        ]);
    }

    /** Service locations are a relation, not a column. */
    private function pullLocations(array &$data): ?array
    {
        if (! array_key_exists('service_location_ids', $data)) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', $data['service_location_ids'] ?? [])));
        unset($data['service_location_ids']);

        return $ids;
    }

    private function enforcePlanLimits(string $role): void
    {
        $tenant = app(TenantContext::class)->tenant();
        $plan = $tenant?->plan;
        if (! $plan) {
            return;
        }
        $active = User::inTenant()->whereIn('status', ['active', 'invited'])->where('role', '!=', Role::Customer->value);
        if ($role === Role::Technician->value && (clone $active)->where('role', Role::Technician->value)->count() >= $plan->max_technicians) {
            throw ValidationException::withMessages(['role' => "Your {$plan->name} plan allows up to {$plan->max_technicians} technicians. Upgrade to add more."]);
        }
        if ($role !== Role::Technician->value && (clone $active)->where('role', '!=', Role::Technician->value)->count() >= $plan->max_users) {
            throw ValidationException::withMessages(['role' => "Your {$plan->name} plan allows up to {$plan->max_users} office users. Upgrade to add more."]);
        }
    }

    private function sendInvite(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $user->notify(new UserInvitation($token, app(TenantContext::class)->tenant()->name, $user->role->label()));
    }
}
