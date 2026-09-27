<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_customer_with_products_in_one_request(): void
    {
        ['tenant' => $tenant, 'admin' => $admin] = $this->makeTenant();

        $response = $this->actingAsUser($admin)->postJson('/api/v1/customers', [
            'name' => 'New Customer',
            'phone' => '9876543210',
            'products' => [
                ['serial_no' => 'SN1001'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'New Customer');
        $this->assertCount(1, $response->json('data.products'));
    }
}
