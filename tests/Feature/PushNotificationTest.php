<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Services\Messaging\FcmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_registration_test_push_and_logout_unregisters(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $token = $tech->createToken('phone')->plainTextToken;
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/v1/auth/device/test', [], $auth)->assertStatus(422);

        $this->postJson('/api/v1/auth/device', ['token' => 'fcm-token-1', 'platform' => 'android'], $auth)->assertOk();
        $this->postJson('/api/v1/auth/device/test', [], $auth)->assertOk()->assertJsonPath('data.devices', 1);
        $this->assertTrue(NotificationLog::where('user_id', $tech->id)->where('type', 'test')->where('channel', 'push')->where('recipient', 'fcm-token-1')->exists());

        $this->postJson('/api/v1/auth/logout', ['device_token' => 'fcm-token-1'], $auth)->assertOk();
        $this->assertFalse(DeviceToken::where('token', 'fcm-token-1')->exists());
    }

    /** Old app builds re-register in a loop; that must only hit its own limit, never the rest of the API. */
    public function test_device_registration_flood_does_not_block_other_requests(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $auth = ['Authorization' => 'Bearer '.$tech->createToken('phone')->plainTextToken];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/device', ['token' => 'loop-token', 'platform' => 'android'], $auth)->assertOk();
        }
        $this->postJson('/api/v1/auth/device', ['token' => 'loop-token', 'platform' => 'android'], $auth)->assertStatus(429);
        // Everything else still works.
        $this->getJson('/api/v1/auth/me', $auth)->assertOk()->assertHeader('X-RateLimit-Remaining', '179');
        $this->assertSame(1, DeviceToken::where('token', 'loop-token')->count());
    }

    public function test_fcm_payload_uses_app_channel_and_flags_dead_tokens(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // Windows PHP builds need an explicit openssl.cnf to generate keys.
        $cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (! getenv('OPENSSL_CONF') && is_file($cnf)) {
            $options['config'] = $cnf;
        }
        $key = @openssl_pkey_new($options);
        if (! $key || ! @openssl_pkey_export($key, $pem, null, $options)) {
            $this->markTestSkipped('OpenSSL key generation is not available in this PHP build.');
        }
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode(['project_id' => 'servon-test', 'client_email' => 'svc@servon-test.iam.gserviceaccount.com', 'private_key' => $pem]));
        Cache::forget('fcm_access_token');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/servon-test/messages/1'])
                ->push(['name' => 'projects/servon-test/messages/2'])
                ->push(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $fcm = new FcmProvider(['credentials' => $path]);

        $ok = $fcm->send('tok', 'Job SC-1 assigned', ['title' => 'New job', 'data' => ['job_id' => 5]]);
        $this->assertTrue($ok['ok']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com')
            && json_decode($r->body(), true)['message']['android']['notification']['channel_id'] === 'default'
            && (json_decode($r->body(), true)['message']['data']['job_id'] ?? null) === '5');

        // No extra data must still encode as a JSON object ({}), not a list ([]).
        $this->assertTrue($fcm->send('tok', 'Reminder')['ok']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com') && str_contains($r->body(), '"data":{}'));

        $dead = $fcm->send('tok', 'x');
        $this->assertFalse($dead['ok']);
        $this->assertTrue($dead['invalid_token']);
        @unlink($path);
    }
}
