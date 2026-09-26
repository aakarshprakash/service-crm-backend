<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Role;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ServiceJob;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Support\Audit;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Super Admin panel (§5.1). No tenant business data is exposed – only counts.
 */
class PlatformController extends Controller
{
    /** FR-1.3 platform-wide usage metrics. */
    public function metrics(): JsonResponse
    {
        $jobs = app(TenantContext::class)->withoutScope(fn () => [
            'total' => ServiceJob::count(),
            'this_month' => ServiceJob::where('created_at', '>=', now()->startOfMonth())->count(),
            'completed_this_month' => ServiceJob::where('completed_at', '>=', now()->startOfMonth())->count(),
            'trend' => ServiceJob::where('created_at', '>=', now()->subMonths(5)->startOfMonth())
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
                ->groupBy('month')->orderBy('month')->pluck('total', 'month'),
        ]);

        $mrr = Tenant::where('status', 'active')->join('subscription_plans', 'subscription_plans.id', '=', 'tenants.plan_id')
            ->sum(DB::raw("CASE WHEN subscription_plans.billing_cycle = 'yearly' THEN subscription_plans.price / 12 ELSE subscription_plans.price END"));

        return $this->ok([
            'tenants' => Tenant::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'tenants_total' => Tenant::count(),
            'new_tenants_this_month' => Tenant::where('created_at', '>=', now()->startOfMonth())->count(),
            'users' => User::whereNotNull('tenant_id')->where('role', '!=', Role::Customer->value)->where('status', 'active')->count(),
            'technicians' => User::where('role', Role::Technician->value)->where('status', 'active')->count(),
            'active_technicians_7d' => User::where('role', Role::Technician->value)->where('last_login_at', '>=', now()->subDays(7))->count(),
            'jobs' => $jobs,
            'mrr' => (int) $mrr,
            'plans' => SubscriptionPlan::withCount(['tenants' => fn ($q) => $q->where('status', '!=', 'suspended')])->get(['id', 'name', 'price', 'billing_cycle']),
        ]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $tenants = Tenant::with('plan:id,name')
            ->withCount([
                'users as staff_count' => fn ($q) => $q->where('role', '!=', Role::Customer->value),
                'users as technician_count' => fn ($q) => $q->where('role', Role::Technician->value),
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = $this->like($request->string('search'));
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        $jobCounts = app(TenantContext::class)->withoutScope(fn () => ServiceJob::whereIn('tenant_id', collect($tenants->items())->pluck('id'))
            ->selectRaw('tenant_id, COUNT(*) as total')->groupBy('tenant_id')->pluck('total', 'tenant_id'));

        return $this->paginated($tenants, fn ($t) => $t->toArray() + ['job_count' => (int) ($jobCounts[$t->id] ?? 0)]);
    }

    public function showTenant(int $id): JsonResponse
    {
        $tenant = Tenant::with(['plan', 'subscriptions.plan:id,name'])->findOrFail($id);
        $admins = User::where('tenant_id', $id)->where('role', Role::Admin->value)->get(['id', 'name', 'email', 'status', 'last_login_at']);

        return $this->ok(['tenant' => $tenant, 'admins' => $admins]);
    }

    public function storeTenant(Request $request, TenantProvisioningService $provisioning): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenants', 'slug')],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', PasswordRule::defaults()],
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'status' => ['required', Rule::in(['trial', 'active'])],
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['nullable', Rule::in(['INR', 'USD', 'EUR', 'GBP', 'AED'])],
        ]);
        ['tenant' => $tenant] = $provisioning->provision($data);

        return $this->created($tenant, 'Tenant created.');
    }

    public function updateTenant(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'plan_id' => ['sometimes', 'integer', 'exists:subscription_plans,id'],
            'trial_ends_at' => ['nullable', 'date'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);
        DB::transaction(function () use ($tenant, $data) {
            if (isset($data['plan_id']) && $data['plan_id'] !== $tenant->plan_id) {
                TenantSubscription::where('tenant_id', $tenant->id)->whereIn('status', ['trial', 'active'])->update(['status' => 'cancelled', 'ends_at' => now()]);
                TenantSubscription::create(['tenant_id' => $tenant->id, 'plan_id' => $data['plan_id'], 'status' => $tenant->status === 'trial' ? 'trial' : 'active', 'starts_at' => now()]);
            }
            $tenant->update($data);
        });

        return $this->ok($tenant->load('plan:id,name'), 'Tenant updated.');
    }

    /** FR-1.1 suspend / activate. Suspension blocks all tenant users immediately. */
    public function setTenantStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['trial', 'active', 'suspended'])]]);
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => $data['status']]);
        if ($data['status'] === 'suspended') {
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', User::where('tenant_id', $tenant->id)->select('id'))->delete();
        }

        return $this->ok($tenant, 'Tenant '.($data['status'] === 'suspended' ? 'suspended' : 'activated').'.');
    }

    public function destroyTenant(Request $request, int $id): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        $tenant = Tenant::findOrFail($id);
        if ($request->string('confirm')->toString() !== $tenant->slug) {
            throw ValidationException::withMessages(['confirm' => 'Type the tenant code to confirm deletion.']);
        }
        $tenant->update(['status' => 'suspended']);
        $tenant->delete(); // soft delete – data retained for recovery / legal hold

        return $this->ok(null, 'Tenant deleted.');
    }

    // ---- Plans (FR-1.2) ------------------------------------------------

    public function plans(): JsonResponse
    {
        return $this->ok(SubscriptionPlan::withCount('tenants')->orderBy('price')->get());
    }

    public function storePlan(Request $request): JsonResponse
    {
        return $this->created(SubscriptionPlan::create($request->validate($this->planRules())), 'Plan created.');
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        $plan->update($request->validate($this->planRules($plan)));

        return $this->ok($plan, 'Plan updated.');
    }

    // ---- Impersonation (FR-1.5) – always audited ------------------------

    public function impersonate(Request $request, int $tenantId): JsonResponse
    {
        $admin = User::where('tenant_id', $tenantId)->where('role', Role::Admin->value)->where('status', 'active')->oldest('id')->first();
        if (! $admin) {
            throw ValidationException::withMessages(['tenant' => 'This tenant has no active admin to impersonate.']);
        }
        $superAdmin = $request->user();
        Audit::log('impersonation.started', $admin, ['by' => $superAdmin->email], $tenantId);

        $payload = ['user' => AuthController::profileFor($admin)];

        if ($request->hasSession()) {
            Auth::guard('web')->login($admin);
            $request->session()->regenerate();
            $request->session()->put('impersonator_id', $superAdmin->id);
        } else {
            $payload['token'] = $this->swapToken($request, $admin, Impersonation::tokenName($superAdmin->id));
        }

        return $this->ok($payload, "You are now signed in as {$admin->name}.");
    }

    public function stopImpersonating(Request $request): JsonResponse
    {
        $impersonatorId = Impersonation::id($request);
        $original = $impersonatorId ? User::where('role', Role::SuperAdmin->value)->find($impersonatorId) : null;
        abort_unless($original, 403);

        Audit::log('impersonation.ended', $request->user(), ['by' => $original->email], $request->user()->tenant_id);

        $payload = ['user' => AuthController::profileFor($original)];

        if ($request->hasSession()) {
            $request->session()->forget('impersonator_id');
            Auth::guard('web')->login($original);
            $request->session()->regenerate();
        } else {
            $payload['token'] = $this->swapToken($request, $original, 'web');
        }

        return $this->ok($payload);
    }

    /** Revokes the caller's token and issues one for $user, so the client swaps identity. */
    private function swapToken(Request $request, User $user, string $name): string
    {
        $current = $request->user()->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return $user->createToken($name, ['*'], now()->addDay())->plainTextToken;
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $rows = AuditLog::with('user:id,name,email')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('action', 'like', 'impersonation.%')->orWhere('action', 'like', 'tenant.%'))
            ->latest('id')->paginate($this->perPage($request));

        return $this->paginated($rows);
    }

    private function planRules(?SubscriptionPlan $plan = null): array
    {
        $req = $plan ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:100'],
            'code' => [$req, 'string', 'max:50', 'alpha_dash', Rule::unique('subscription_plans', 'code')->ignore($plan?->id)],
            'price' => [$req, 'integer', 'min:0'],
            'billing_cycle' => [$req, Rule::in(['monthly', 'yearly'])],
            'max_users' => [$req, 'integer', 'min:1', 'max:100000'],
            'max_technicians' => [$req, 'integer', 'min:1', 'max:100000'],
            'features' => ['nullable', 'array'],
            'features.*' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
