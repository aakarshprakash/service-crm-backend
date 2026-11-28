<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Finding a job by its CRM call number or the customer's CRM ID, typed in part or without separators. */
class JobCrmSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ['tenant' => $this->tenant, 'admin' => $this->admin] = $this->makeTenant();
        $this->actingAsUser($this->admin);
    }

    private function found(string $term): array
    {
        return array_column($this->getJson('/api/v1/jobs?search='.urlencode($term))->assertOk()->json('data'), 'id');
    }

    public function test_a_job_is_found_by_any_form_of_its_crm_numbers(): void
    {
        $customer = $this->makeCustomer($this->tenant, ['crm_id' => 'LG/CRM-5001', 'phone' => '9811100001']);
        $job = $this->makeJob($this->tenant, $customer, null, ['crm_call_id' => 'SC-2611-00069'])->id;
        $other = $this->makeJob($this->tenant, $this->makeCustomer($this->tenant, ['crm_id' => 'CRM7002', 'phone' => '9811100002']), null, ['crm_call_id' => 'SC-2611-00070'])->id;

        // The job's CRM call number: in full, in part, or without its dashes.
        $this->assertSame([$job], $this->found('SC-2611-00069'));
        $this->assertSame([$job], $this->found('00069'));
        $this->assertSame([$job], $this->found('SC261100069'));
        $this->assertSame([$job], $this->found('sc 2611 00069'));
        $this->assertEqualsCanonicalizing([$job, $other], $this->found('SC-2611'));

        // The customer's CRM ID: in full, in part, or without its separators.
        $this->assertSame([$job], $this->found('LG/CRM-5001'));
        $this->assertSame([$job], $this->found('5001'));
        $this->assertSame([$job], $this->found('LGCRM5001'));
        $this->assertSame([$other], $this->found('crm7002'));

        $this->assertSame([], $this->found('99999'));
    }
}
