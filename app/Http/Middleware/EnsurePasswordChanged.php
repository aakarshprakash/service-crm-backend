<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user signed in with a temporary password (set by their admin) must choose their own
 * before using anything else. Only profile, password change and sign-out stay open.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = ['api/v1/auth/me', 'api/v1/auth/password', 'api/v1/auth/logout', 'api/v1/auth/device', 'api/v1/version'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->mustChangePassword() && ! in_array($request->path(), self::ALLOWED, true)) {
            return response()->json([
                'message' => 'Choose a new password to continue. Your administrator set a temporary one.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
