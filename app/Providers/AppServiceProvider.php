<?php

namespace App\Providers;

use App\Support\AgentApi\AccountAwareAccessTokenRepository;
use App\Support\AgentApi\AccountAwareAuthCodeRepository;
use App\Support\AgentApi\AccountAwareRefreshTokenRepository;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiTokenPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registration runs before package providers boot, so this prevents the
        // unused device-code routes and grant from being registered at all.
        Passport::$deviceCodeGrantEnabled = false;
        $this->app->bind(AccessTokenRepository::class, AccountAwareAccessTokenRepository::class);
        $this->app->bind(AuthCodeRepository::class, AccountAwareAuthCodeRepository::class);
        $this->app->bind(RefreshTokenRepository::class, AccountAwareRefreshTokenRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::loadKeysFrom(storage_path('app/private/oauth'));
        Passport::tokensCan(AgentApiScopes::descriptions());
        Passport::tokensExpireIn(now()->addMinutes(AgentApiTokenPolicy::ACCESS_TOKEN_LIFETIME_MINUTES));
        Passport::refreshTokensExpireIn(now()->addDays(AgentApiTokenPolicy::REFRESH_TOKEN_LIFETIME_DAYS));
        Passport::personalAccessTokensExpireIn(now()->addMinutes(AgentApiTokenPolicy::ACCESS_TOKEN_LIFETIME_MINUTES));
        Passport::authorizationView('oauth.authorize');

        // Authorization Code + PKCE is the supported interactive grant. Passport
        // rotates refresh tokens by default; unused grant types stay disabled.
        RateLimiter::for('agent-api', function (Request $request): Limit {
            $user = $request->user('api');
            $key = $user === null
                ? 'unauthenticated:'.hash('sha256', (string) $request->ip())
                : 'user:'.$user->getAuthIdentifier();

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('agent-api-authentication', function (Request $request): Limit {
            // This global limiter runs before routing, so normalize numeric path
            // parameters ourselves. Otherwise an attacker can reset the budget
            // merely by changing a patient or record id in the URL.
            $normalizedPath = preg_replace('#(?<=/)\d+(?=/|$)#', '{id}', $request->path());
            $endpoint = $request->method().':'.($normalizedPath ?? $request->path());
            $key = hash('sha256', (string) $request->ip()).':'.$endpoint;

            return Limit::perMinute((int) config('agent_api.authentication_attempts_per_minute', 300))->by($key);
        });

        RateLimiter::for('agent-api-token-exchange', function (Request $request): Limit {
            return Limit::perMinute((int) config('agent_api.token_exchange_attempts_per_minute', 60))
                ->by(hash('sha256', (string) $request->ip()));
        });

        RateLimiter::for('agent-api-authorization', function (Request $request): Limit {
            return Limit::perMinute((int) config('agent_api.authorization_attempts_per_minute', 30))
                ->by(hash('sha256', (string) $request->ip()));
        });
    }
}
