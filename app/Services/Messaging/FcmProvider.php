<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Firebase Cloud Messaging HTTP v1 using a service-account JSON key. */
class FcmProvider implements MessagingProviderInterface
{
    public function __construct(private array $config) {}

    public function send(string $to, string $message, array $meta = []): array
    {
        $credentials = is_readable((string) $this->config['credentials'])
            ? json_decode((string) file_get_contents($this->config['credentials']), true)
            : null;
        if (! $credentials) {
            return ['ok' => false, 'error' => 'FCM credentials file missing or invalid'];
        }

        $response = Http::timeout(10)->withToken($this->accessToken($credentials))
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                'message' => [
                    'token' => $to,
                    'notification' => ['title' => $meta['title'] ?? config('app.name'), 'body' => $message],
                    'data' => array_map('strval', $meta['data'] ?? []),
                    // "default" is the channel the technician app creates (high importance, sound).
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'default', 'sound' => 'default', 'default_vibrate_timings' => true]],
                ],
            ]);

        if ($response->successful()) {
            return ['ok' => true, 'error' => null];
        }
        $code = collect($response->json('error.details') ?? [])->pluck('errorCode')->filter()->first();

        return [
            'ok' => false,
            'error' => $response->json('error.message') ?? 'FCM HTTP '.$response->status(),
            // The app was uninstalled or the token rotated: stop sending to it.
            'invalid_token' => $code === 'UNREGISTERED' || ($code === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'registration token')),
        ];
    }

    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm_access_token', 3000, function () use ($credentials) {
            $now = time();
            $encode = fn ($data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
            $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);
            openssl_sign($unsigned, $signature, $credentials['private_key'], 'sha256WithRSAEncryption');
            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }
}
