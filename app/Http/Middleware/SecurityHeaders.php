<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // API responses are data only: nothing should ever be executed or framed.
        if ($request->is('api/*')) {
            $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'";
            $headers['Cache-Control'] = $response->headers->get('Cache-Control') === 'no-cache, private'
                ? 'no-store, private'
                : $response->headers->get('Cache-Control');
        }

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $key => $value) {
            if ($value !== null && ! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
