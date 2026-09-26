<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the authenticated user (never from client input) and
 * blocks deactivated users / suspended tenants.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $user->isActive()) {
            $this->logout($request);

            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        if ($user->isSuperAdmin()) {
            $this->context->set(null);

            return $next($request);
        }

        $tenant = $user->tenant;
        if (! $tenant || ! $tenant->isUsable()) {
            $message = $tenant?->status === 'trial'
                ? 'Your trial has ended. Please contact support to activate a subscription.'
                : 'This company account is suspended. Please contact support.';

            return response()->json(['message' => $message, 'code' => 'tenant_unavailable'], 403);
        }

        $this->context->set($tenant->id);

        return $next($request);
    }

    private function logout(Request $request): void
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }
    }
}
