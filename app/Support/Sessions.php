<?php

namespace App\Support;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-in sessions (Sanctum tokens) for the web app, the Technician app and the Manager app:
 * issued with where they came from, listed for the person and their admin, revoked remotely.
 */
class Sessions
{
    /** @param  'web'|'technician'|'manager'  $app */
    public static function issue(User $user, string $name, array $abilities, \DateTimeInterface $expires, string $app, ?Request $request = null): NewAccessToken
    {
        $token = $user->createToken($name, $abilities, $expires);
        $request ??= request();
        $token->accessToken->forceFill([
            'app' => $app,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null,
        ])->save();

        return $token;
    }

    /** Live sessions, most recently used first. */
    public static function list(User $user, ?int $currentId = null): array
    {
        $idle = config('sanctum.idle_days');

        return $user->tokens()->get()
            ->filter(fn (PersonalAccessToken $t) => (! $t->expires_at || $t->expires_at->isFuture())
                && (! $idle || ($t->last_used_at ?? $t->created_at)->gt(now()->subDays($idle))))
            ->sortByDesc(fn (PersonalAccessToken $t) => $t->last_used_at ?? $t->created_at)
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'app' => $t->app ?? (in_array('app:manager', $t->abilities ?? [], true) ? 'manager' : ($t->name === 'web' ? 'web' : 'technician')),
                'device' => $t->name === 'web' ? self::browser($t->user_agent) : $t->name,
                'ip_address' => $t->ip_address,
                'signed_in_at' => $t->created_at,
                'last_used_at' => $t->last_used_at,
                'expires_at' => $t->expires_at,
                'current' => $t->id === $currentId,
            ])->values()->all();
    }

    /**
     * Signs the user out everywhere (or everywhere except one session). Without an exception
     * their phones also stop receiving push notifications.
     */
    public static function revokeAll(User $user, ?int $exceptId = null): int
    {
        $count = $user->tokens()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->delete();
        if (! $exceptId) {
            DeviceToken::where('user_id', $user->id)->delete();
        }

        return $count;
    }

    private static function browser(?string $ua): string
    {
        if (! $ua) {
            return 'Web browser';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "$browser on $os" : $browser;
    }
}
