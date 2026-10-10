<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Servon Manager (office staff phone app) is a plan add-on. */
class ManagerAppTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $email, string $app = 'manager')
    {
        return $this->postJson('/api/v1/auth/token', ['login' => $email, 'password' => self::PASSWORD, 'device_name' => 'Test phone', 'app' => $app]);
    }

    public function test_manager_app_needs_the_plan_add_on(): void
    {
        $this->makeTenant('acme');
        $this->signIn('admin@acme.test')->assertStatus(422)->assertJsonPath('errors.login.0', fn ($m) => str_contains($m, 'not included'));
        // The technician app is unaffected.
        $this->signIn('admin@acme.test', 'technician')->assertOk();
    }

    public function test_office_staff_sign_in_and_technicians_are_sent_to_their_app(): void
    {
        ['tenant' => $tenant] = $this->makeTenant('acme', ['manager_app' => true]);
        $res = $this->signIn('admin@acme.test')->assertOk()->assertJsonPath('data.user.tenant.manager_app', true);
        $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$res->json('data.token')])->assertOk();

        $coordinator = $this->makeUser($tenant, Role::Coordinator);
        $this->signIn($coordinator->email)->assertOk();

        $tech = $this->makeUser($tenant, Role::Technician);
        $this->signIn($tech->email)->assertStatus(422)->assertJsonPath('errors.login.0', 'Technicians sign in with the Servon Technician app.');
    }

    public function test_overdue_filter_matches_the_dashboard_count(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant('acme', ['manager_app' => true]);
        $customer = $this->makeCustomer($tenant);
        $late = $this->makeJob($tenant, $customer, null, ['scheduled_at' => now()->subDays(2)]);
        $this->makeJob($tenant, $customer, null, ['scheduled_at' => now()->addDay()]);
        $this->makeJob($tenant, $customer, null, ['scheduled_at' => now()->subDays(3), 'status' => 'completed']);

        $this->actingAsUser($admin);
        $this->getJson('/api/v1/jobs?overdue=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late->id);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.jobs.overdue', 1);
    }

    public function test_manager_sessions_end_when_the_plan_drops_the_add_on(): void
    {
        ['tenant' => $tenant] = $this->makeTenant('acme', ['manager_app' => true]);
        $manager = ['Authorization' => 'Bearer '.$this->signIn('admin@acme.test')->json('data.token')];
        $this->forgetGuards();
        $web = ['Authorization' => 'Bearer '.$this->signIn('admin@acme.test', 'technician')->json('data.token')];

        $plan = SubscriptionPlan::find($tenant->plan_id);
        $plan->update(['features' => array_merge($plan->features, ['manager_app' => false])]);

        $this->forgetGuards();
        $this->getJson('/api/v1/auth/me', $manager)->assertStatus(401)->assertJsonPath('code', 'manager_app_unavailable');
        $this->forgetGuards();
        $this->getJson('/api/v1/auth/me', $web)->assertOk();
        $this->forgetGuards();
        $this->getJson('/api/v1/auth/me', $manager)->assertStatus(401); // token was revoked
    }
}
