<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FORCE_HTTPS=true: plain-HTTP requests are never served. Page and API reads are redirected
 * to HTTPS; anything that sends data (sign-in, uploads…) is refused instead of redirected,
 * because by then the password or token has already crossed the network unencrypted.
 * Off by default: behind a proxy that hides HTTPS, set TRUSTED_PROXIES first or it loops.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.force_https') || $request->secure()) {
            return $next($request);
        }
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return response()->json(['message' => 'Use https:// to connect to Servon.'], 403);
    }
}
