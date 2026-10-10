<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Messaging\MessagingManager;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\TenantContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: reset between requests and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(PaymentGatewayManager::class);
        $this->app->singleton(MessagingManager::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        JsonResource::withoutWrapping();

        Password::defaults(fn () => Password::min(8)->letters()->mixedCase()->numbers()
            ->when($this->app->isProduction(), fn ($rule) => $rule->uncompromised()));

        // RBAC: one Gate per ability from config/permissions.php (SRS §4).
        foreach (config('permissions') as $ability => $roles) {
            Gate::define($ability, fn (User $user) => in_array($user->role->value, $roles, true));
        }

        // Password-reset links point to the React app.
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));

        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            $idle = config('sanctum.idle_days');

            return $isValid && (! $idle || ($token->last_used_at ?? $token->created_at)->gt(now()->subDays($idle)));
        });

        $this->configureRateLimiting();
        $this->configureApiDocs();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email', $request->input('login'))).'|'.$request->ip();

            return [Limit::perMinute(5)->by($key), Limit::perMinute(30)->by($request->ip())];
        });

        RateLimiter::for('otp', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone'));

            return [
                Limit::perMinute(3)->by('otp-m:'.$phone),
                Limit::perHour(10)->by('otp-h:'.$phone),
                Limit::perHour(30)->by('otp-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(10)->by('device:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(40)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }

    private function configureApiDocs(): void
    {
        Scramble::configure()
            ->routes(fn ($route) => str_starts_with($route->uri, 'api/v1'))
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

        // OpenAPI docs are visible to everyone locally, to super admins only elsewhere.
        Gate::define('viewApiDocs', fn (?User $user) => $this->app->isLocal() || $user?->isSuperAdmin());
    }
}
