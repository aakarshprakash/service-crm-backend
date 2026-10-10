<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OtpService;
use App\Services\TenantProvisioningService;
use App\Support\Audit;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    /** Lifetime of a web app bearer token. */
    private const WEB_TOKEN_DAYS = 30;

    /** Public: active subscription plans for the sign-up page. */
    public function plans(): JsonResponse
    {
        return $this->ok(SubscriptionPlan::where('is_active', true)->orderBy('price')->get());
    }

    /** FR-1.4 tenant self sign-up (trial). */
    public function register(Request $request, TenantProvisioningService $provisioning): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenants', 'slug'), Rule::notIn(['www', 'api', 'admin', 'app', 'portal', 'mail'])],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 \-]{7,20}$/'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
        ], ['slug.regex' => 'Use lowercase letters, numbers and single hyphens only.']);

        ['tenant' => $tenant, 'admin' => $admin] = $provisioning->provision($data);
        Audit::log('tenant.registered', $tenant, [], $tenant->id);

        return $this->created($this->authPayload($request, $admin->fresh()), 'Your account is ready. Your free trial has started.');
    }

    /**
     * Web app login for staff and super admins, covering every role and the 2FA step.
     * Mobile clients use the narrower POST /auth/token instead.
     */
    public function login(Request $request, Google2FA $google2fa): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        $user = $this->attempt(strtolower($credentials['email']), $credentials['password']);

        if ($user->hasTwoFactor()) {
            if (empty($credentials['code'])) {
                return $this->ok(['two_factor' => true], 'Enter the 6-digit code from your authenticator app.');
            }
            if (! $google2fa->verifyKey($user->two_factor_secret, $credentials['code'], 1)) {
                throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
            }
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        Audit::log('auth.login', $user, [], $user->tenant_id);

        return $this->ok($this->authPayload($request, $user));
    }

    /** Android / API clients: email or phone + password → Sanctum personal access token. */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:40'],
            // Which phone app is signing in; older technician builds don't send it.
            'app' => ['nullable', Rule::in(['technician', 'manager'])],
        ]);
        $manager = ($data['app'] ?? null) === 'manager';

        $login = strtolower(trim($data['login']));
        if (! str_contains($login, '@')) {
            // Phone numbers are unique per tenant, so a company code disambiguates.
            $query = User::where('phone', $data['login']);
            if (! empty($data['company'])) {
                $query->whereHas('tenant', fn ($q) => $q->where('slug', $data['company']));
            }
            $candidates = $query->limit(2)->get();
            if ($candidates->count() > 1) {
                throw ValidationException::withMessages(['company' => 'Enter your company code to continue.']);
            }
            $login = $candidates->first()?->email ?? '__none__';
        }

        $user = $this->attempt($login, $data['password']);
        if (! in_array($user->role, [Role::Technician, Role::Admin, Role::Coordinator, Role::Accountant], true)) {
            throw ValidationException::withMessages(['login' => 'This account cannot sign in to the mobile app.']);
        }
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['login' => 'Accounts with two-factor authentication must sign in on the web.']);
        }
        if ($manager && $user->role === Role::Technician) {
            throw ValidationException::withMessages(['login' => 'Technicians sign in with the Servon Technician app.']);
        }
        if ($manager && ! $user->tenant?->feature('manager_app')) {
            throw ValidationException::withMessages(['login' => 'The Servon Manager app is not included in your company’s plan. Contact Servon support to add it.']);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        // Manager app tokens are marked so they stop working if the plan loses the add-on (ResolveTenant).
        $token = $user->createToken(mb_substr($data['device_name'], 0, 100), $manager ? ['*', 'app:manager'] : ['*'], now()->addDays(30));
        Audit::log('auth.token', $user, ['device' => $data['device_name']], $user->tenant_id);

        return $this->ok(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at, 'user' => $this->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        // The phone stops receiving this user's push notifications once they sign out.
        if ($request->filled('device_token') && $request->user()) {
            DeviceToken::where('user_id', $request->user()->id)->where('token', (string) $request->input('device_token'))->delete();
        }
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->ok(null, 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(['user' => $this->profile($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 \-]{7,20}$/'],
        ]);
        $user->update($data);

        return $this->ok(['user' => $this->profile($user)], 'Profile updated.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::defaults(), 'different:current_password'],
        ]);
        $user = $request->user();
        $user->update(['password' => $data['password']]);
        // Sign out other devices.
        $user->tokens()->when($user->currentAccessToken() instanceof PersonalAccessToken,
            fn ($q) => $q->where('id', '!=', $user->currentAccessToken()->id))->delete();
        if ($request->hasSession()) {
            Auth::guard('web')->logoutOtherDevices($data['password']);
        }

        return $this->ok(null, 'Password changed.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::broker()->sendResetLink(['email' => strtolower($request->string('email'))]);

        // Same response whether or not the account exists (no user enumeration).
        return $this->ok(null, 'If an account exists for that email, a reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset(
            ['email' => strtolower($data['email'])] + $data,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'status' => $user->status === 'invited' ? 'active' : $user->status])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired.']);
        }

        return $this->ok(null, 'Password updated. You can now sign in.');
    }

    // ---- Two-factor authentication (TOTP) for admins -------------------

    public function twoFactorSetup(Request $request, Google2FA $google2fa): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['two_factor' => 'Two-factor authentication is already enabled.']);
        }
        $secret = $google2fa->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return $this->ok([
            'secret' => $secret,
            'otpauth_url' => $google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret),
        ]);
    }

    public function twoFactorConfirm(Request $request, Google2FA $google2fa): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if (! $user->two_factor_secret || ! $google2fa->verifyKey($user->two_factor_secret, $request->string('code'), 1)) {
            throw ValidationException::withMessages(['code' => 'Invalid code. Check your authenticator app and try again.']);
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        Audit::log('auth.2fa_enabled', $user, [], $user->tenant_id);

        return $this->ok(['user' => $this->profile($user)], 'Two-factor authentication enabled.');
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        Audit::log('auth.2fa_disabled', $user, [], $user->tenant_id);

        return $this->ok(['user' => $this->profile($user)], 'Two-factor authentication disabled.');
    }

    // ---- Customer portal: phone + OTP (FR-11.1) ------------------------

    public function portalCompany(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->first();
        if (! $tenant || ! $tenant->isUsable() || ! $tenant->portalEnabled()) {
            return response()->json(['message' => 'Customer portal is not available for this company.'], 404);
        }

        return $this->ok(['name' => $tenant->name, 'slug' => $tenant->slug, 'logo' => $tenant->logo_path ? true : false]);
    }

    public function otpRequest(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);
        $tenant = $this->portalTenant($data['company']);

        // Only send when the phone belongs to a customer; always answer the same way.
        $exists = app(TenantContext::class)->runAs($tenant->id, fn () => Customer::where('phone', $data['phone'])->exists());
        if ($exists) {
            $otp->issue($tenant, $data['phone'], 'portal_login');
        }

        return $this->ok(null, 'If this number is registered with us, you will receive a verification code.');
    }

    public function otpVerify(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $tenant = $this->portalTenant($data['company']);

        if (! $otp->verify($tenant, $data['phone'], 'portal_login', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The code is invalid or has expired.']);
        }

        $user = app(TenantContext::class)->runAs($tenant->id, function () use ($tenant, $data) {
            $customer = Customer::where('phone', $data['phone'])->oldest('id')->firstOrFail();
            $user = User::where('tenant_id', $tenant->id)->where('role', Role::Customer->value)
                ->where('customer_id', $customer->id)->first();
            if (! $user) {
                $user = new User(['name' => $customer->name, 'phone' => $customer->phone, 'role' => Role::Customer, 'status' => 'active']);
                $user->tenant_id = $tenant->id;
                $user->customer_id = $customer->id;
                $user->save();
            }

            return $user;
        });

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['phone' => 'This account is disabled.']);
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if (! empty($data['device_name'])) {
            $token = $user->createToken($data['device_name'], ['*'], now()->addDays(7));

            return $this->ok(['token' => $token->plainTextToken, 'user' => $this->profile($user)]);
        }

        return $this->ok($this->authPayload($request, $user));
    }

    /**
     * Signs a freshly authenticated user in and builds the response body.
     *
     * A bearer token is always issued: the web app is commonly served from a different
     * domain than the API (e.g. a static host in front of this backend), and browsers
     * block third-party cookies, so a cookie session can't be relied on. The session is
     * still started when the request has one, which keeps same-origin deployments working.
     */
    private function authPayload(Request $request, User $user): array
    {
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate(); // prevents session fixation
        }

        $token = $user->createToken('web', ['*'], now()->addDays(self::WEB_TOKEN_DAYS));

        return ['token' => $token->plainTextToken, 'user' => $this->profile($user)];
    }

    /** FCM token registration for push (§9.4). */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
        ]);
        DeviceToken::where('token', $data['token'])->where('user_id', '!=', $request->user()->id)->delete();
        // The token's app decides which Firebase project's key sends to it (manager may use its own).
        $session = $request->user()->currentAccessToken();
        $app = $session instanceof PersonalAccessToken && in_array('app:manager', $session->abilities ?? [], true) ? 'manager' : 'technician';
        DeviceToken::updateOrCreate(
            ['user_id' => $request->user()->id, 'token' => $data['token']],
            ['platform' => $data['platform'] ?? 'android', 'app' => $app]
        );

        return $this->ok(null, 'Device registered.');
    }

    /** Sends a test push to the signed-in user's phones, so technicians can check notifications work. */
    public function testPush(Request $request, NotificationService $notifications): JsonResponse
    {
        $user = $request->user();
        $devices = $user->deviceTokens()->count();
        if ($devices === 0) {
            abort(422, 'This phone is not registered for notifications yet. Allow notifications for the app and sign in again.');
        }
        $notifications->notifyUser($user, 'test', 'Notifications are working', 'You will get a notification like this when a job is assigned to you.', ['test' => 1]);

        return $this->ok([
            'devices' => $devices,
            'driver' => config('services.messaging.push'),
        ], config('services.messaging.push') === 'fcm'
            ? 'Test notification sent. It should arrive in a few seconds.'
            : 'Push is not configured on the server yet (PUSH_DRIVER is not "fcm"). Ask your administrator.');
    }

    private function attempt(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        // Same hashing work whether or not the account exists (limits enumeration via timing).
        static $dummyHash;
        $dummyHash ??= Hash::make(Str::random(32));
        if ($user && $user->password) {
            $valid = Hash::check($password, $user->password);
        } else {
            Hash::check($password, $dummyHash);
            $valid = false;
        }

        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => $user->status === 'invited'
                ? 'Please set your password using the invitation link first.'
                : 'Your account has been deactivated. Contact your administrator.']);
        }
        if ($user->isCustomer()) {
            throw ValidationException::withMessages(['email' => 'Customers sign in with their phone number on the customer portal.']);
        }
        if ($user->tenant_id && ! $user->tenant?->isUsable()) {
            throw ValidationException::withMessages(['email' => $user->tenant?->status === 'trial'
                ? 'Your free trial has ended. Please contact us to activate your subscription.'
                : 'This company account is suspended. Please contact support.']);
        }

        return $user;
    }

    private function portalTenant(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->first();
        if (! $tenant || ! $tenant->isUsable() || ! $tenant->portalEnabled()) {
            throw ValidationException::withMessages(['company' => 'Customer portal is not available for this company.']);
        }

        return $tenant;
    }

    public static function profileFor(User $user): array
    {
        $tenant = $user->tenant;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'branch_id' => $user->branch_id,
            'customer_id' => $user->customer_id,
            'punch_status' => $user->punch_status,
            'punched_at' => $user->punched_at,
            'two_factor_enabled' => $user->hasTwoFactor(),
            'abilities' => $user->abilities(),
            'impersonating' => Impersonation::id() !== null,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'trial_ends_at' => $tenant->trial_ends_at,
                'online_payments' => $tenant->onlinePaymentsEnabled(),
                'portal_enabled' => $tenant->portalEnabled(),
                'tutorial_mode' => (bool) $tenant->setting('tutorial_mode'),
                'require_signature' => (bool) $tenant->setting('jobs.require_signature', false),
                'manager_app' => $tenant->feature('manager_app'),
            ] : null,
        ];
    }

    private function profile(User $user): array
    {
        return self::profileFor($user);
    }
}
