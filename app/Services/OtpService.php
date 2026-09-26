<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

/**
 * Phone OTP (customer portal login, technician password reset). Codes are hashed,
 * expire after 5 minutes and allow at most 5 attempts.
 */
class OtpService
{
    private const TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private NotificationService $notifications) {}

    public function issue(?Tenant $tenant, string $phone, string $purpose): void
    {
        OtpCode::where(['tenant_id' => $tenant?->id, 'phone' => $phone, 'purpose' => $purpose])
            ->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = app()->environment('local', 'testing') && config('app.fixed_otp')
            ? (string) config('app.fixed_otp')
            : (string) random_int(100000, 999999);

        OtpCode::create([
            'tenant_id' => $tenant?->id,
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $this->notifications->sendOtp($tenant, $phone, $code);
    }

    public function verify(?Tenant $tenant, string $phone, string $purpose, string $code): bool
    {
        $otp = OtpCode::where(['tenant_id' => $tenant?->id, 'phone' => $phone, 'purpose' => $purpose])
            ->whereNull('consumed_at')->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }
        $otp->update(['consumed_at' => now()]);

        return true;
    }
}
