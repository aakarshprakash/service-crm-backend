<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\OtpCode;
use App\Services\OtpService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function portalLogin($tenant, $customer): void
    {
        // Issue a known OTP directly (the real code goes out by SMS).
        OtpCode::create(['tenant_id' => $tenant->id, 'phone' => $customer->phone, 'purpose' => 'portal_login',
            'code_hash' => Hash::make('654321'), 'expires_at' => now()->addMinutes(5)]);
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/otp/verify', ['company' => $tenant->slug, 'phone' => $customer->phone, 'code' => '654321'])
            ->assertOk()->assertJsonPath('data.user.role', 'customer');
    }

    public function test_otp_request_does_not_reveal_whether_phone_exists(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);

        $known = $this->postJson('/api/v1/auth/otp/request', ['company' => $tenant->slug, 'phone' => $customer->phone])->assertOk()->json('message');
        $unknown = $this->postJson('/api/v1/auth/otp/request', ['company' => $tenant->slug, 'phone' => '9000000000'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);
        $this->assertSame(1, OtpCode::count());
        $this->assertNotSame('', OtpCode::first()->code_hash);
    }

    public function test_otp_attempts_are_limited(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        app(OtpService::class)->issue($tenant, $customer->phone, 'portal_login');

        foreach (range(1, 5) as $i) {
            $this->assertFalse(app(OtpService::class)->verify($tenant, $customer->phone, 'portal_login', '000000'));
        }
        // Even a correct code is refused once attempts are exhausted.
        OtpCode::query()->update(['code_hash' => Hash::make('111111')]);
        $this->assertFalse(app(OtpService::class)->verify($tenant, $customer->phone, 'portal_login', '111111'));
    }

    public function test_customer_sees_only_own_jobs_raises_complaint_and_reviews(): void
    {
        ['tenant' => $tenant] = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $other = $this->makeCustomer($tenant);
        $tech = $this->makeUser($tenant, Role::Technician);
        $mine = $this->makeJob($tenant, $customer, $tech, ['status' => 'completed']);
        $theirs = $this->makeJob($tenant, $other);

        $this->portalLogin($tenant, $customer);

        $this->withHeaders($this->spa)->getJson('/api/v1/customer/jobs')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($this->spa)->getJson("/api/v1/customer/jobs/{$theirs->id}")->assertNotFound();
        $this->withHeaders($this->spa)->postJson("/api/v1/customer/jobs/{$theirs->id}/review", ['rating' => 5])->assertNotFound();

        $this->withHeaders($this->spa)->postJson('/api/v1/customer/complaints', ['complaint_details' => 'AC is making noise since morning'])
            ->assertCreated()->assertJsonPath('data.status', 'open');
        // The office is told about a request it didn't log itself.
        $this->assertTrue(\App\Models\NotificationLog::where('tenant_id', $tenant->id)->where('type', 'new_complaint')->where('channel', 'in_app')->exists());

        $this->withHeaders($this->spa)->postJson("/api/v1/customer/jobs/{$mine->id}/review", ['rating' => 6])->assertStatus(422);
        $this->withHeaders($this->spa)->postJson("/api/v1/customer/jobs/{$mine->id}/review", ['rating' => 5, 'comment' => 'Great'])->assertCreated();
        $this->withHeaders($this->spa)->postJson("/api/v1/customer/jobs/{$mine->id}/review", ['rating' => 4])->assertStatus(422);

        // Portal users cannot reach staff endpoints.
        $this->withHeaders($this->spa)->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_every_report_renders_and_exports(): void
    {
        $this->seed(DatabaseSeeder::class);
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $tech = $this->makeUser($tenant, Role::Technician);
        $this->makeJob($tenant, $this->makeCustomer($tenant), $tech);
        $this->makeItem($tenant, 1, ['reorder_level' => 5]);
        $this->actingAsUser($admin);

        $reports = collect($this->getJson('/api/v1/reports')->assertOk()->json('data'))->pluck('key');
        $this->assertCount(22, $reports); // 16 + 6 added in v2.1 (books, receipts, HR)

        foreach ($reports as $key) {
            $this->getJson("/api/v1/reports/{$key}?from=".now()->subMonth()->toDateString().'&to='.now()->toDateString())
                ->assertOk()->assertJsonStructure(['data' => ['title', 'columns', 'rows', 'summary']]);
        }

        $xlsx = $this->get('/api/v1/reports/jobs/export?format=xlsx')->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent()); // zip container
        $pdf = $this->get('/api/v1/reports/low-stock/export?format=pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->getJson('/api/v1/reports/low-stock')->assertJsonPath('data.rows.0._flag', true);
    }

    public function test_dashboards_load(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();
        $this->makeJob($tenant, $this->makeCustomer($tenant));

        $this->actingAsUser($admin)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.jobs.by_status.open', 1)
            ->assertJsonCount(14, 'data.jobs.trend');
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/plans')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_signed_image_url_cannot_be_forged(): void
    {
        $this->get('/api/v1/files/images/1')->assertForbidden();
        $this->get('/api/v1/files/images/1?signature=abc&expires='.(time() + 60))->assertForbidden();
    }
}
