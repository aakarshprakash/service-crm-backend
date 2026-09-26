<?php

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who is impersonating the current user, if anyone.
 *
 * Cookie sessions keep this in the session. Token clients can't - so the
 * impersonator's id rides in the access token's name instead.
 */
class Impersonation
{
    public const TOKEN_PREFIX = 'impersonating:';

    /** Names the token issued when $superAdminId starts impersonating someone. */
    public static function tokenName(int $superAdminId): string
    {
        return self::TOKEN_PREFIX.$superAdminId;
    }

    public static function id(?Request $request = null): ?int
    {
        $request ??= request();

        if ($request->hasSession() && $request->session()->has('impersonator_id')) {
            return (int) $request->session()->get('impersonator_id');
        }

        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && str_starts_with($token->name, self::TOKEN_PREFIX)) {
            return (int) substr($token->name, strlen(self::TOKEN_PREFIX));
        }

        return null;
    }
}
