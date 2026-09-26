<?php

namespace App\Services\Payments;

use App\Models\Tenant;

/**
 * Resolves the gateway for a tenant: the tenant's own merchant credentials if
 * configured (§5.14), otherwise the platform account from config/services.php.
 */
class PaymentGatewayManager
{
    public function forTenant(Tenant $tenant): ?PaymentGatewayInterface
    {
        $credentials = $tenant->gateway_credentials ?: [];
        $driver = $credentials['driver'] ?? config('services.payments.default', 'razorpay');

        return match ($driver) {
            'razorpay' => $this->razorpay($credentials),
            default => null,
        };
    }

    private function razorpay(array $credentials): ?RazorpayGateway
    {
        $key = $credentials['key_id'] ?? config('services.razorpay.key_id');
        $secret = $credentials['key_secret'] ?? config('services.razorpay.key_secret');
        $webhook = $credentials['webhook_secret'] ?? config('services.razorpay.webhook_secret');

        return $key && $secret ? new RazorpayGateway($key, $secret, $webhook) : null;
    }
}
