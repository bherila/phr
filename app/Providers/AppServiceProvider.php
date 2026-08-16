<?php

namespace App\Providers;

use App\Support\AgentApi\AccountAwareRefreshTokenRepository;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
        $this->app->bind(RefreshTokenRepository::class, AccountAwareRefreshTokenRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::loadKeysFrom(storage_path('app/private/oauth'));
        Passport::tokensCan(AgentApiScopes::descriptions());
        Passport::tokensExpireIn(now()->addMinutes(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMinutes(15));
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
    }
}
