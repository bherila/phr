<?php

namespace App\Providers;

use App\GenAiProcessor\External\PhrMcpAttachmentResolver;
use App\GenAiProcessor\External\PhrMcpCompletionDelivery;
use App\GenAiProcessor\External\PhrMcpMailboxAccessResolver;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Support\AgentApi\AccountAwareAccessTokenRepository;
use App\Support\AgentApi\AccountAwareAuthCodeRepository;
use App\Support\AgentApi\AccountAwareRefreshTokenRepository;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiTokenPolicy;
use Bherila\GenAiLaravel\Contracts\AttachmentResolver;
use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestClaimed;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestFailed;
use Bherila\McpLaravelBridge\Http\AgentApiTransport;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        $this->app->bind(AgentApiTransport::class, InternalAgentApiTransport::class);
        $this->app->bind(AccessTokenRepository::class, AccountAwareAccessTokenRepository::class);
        $this->app->bind(AuthCodeRepository::class, AccountAwareAuthCodeRepository::class);
        $this->app->bind(RefreshTokenRepository::class, AccountAwareRefreshTokenRepository::class);
        $this->app->bind(MailboxAccessResolver::class, PhrMcpMailboxAccessResolver::class);
        $this->app->bind(AttachmentResolver::class, PhrMcpAttachmentResolver::class);
        $this->app->bind(CompletionDelivery::class, PhrMcpCompletionDelivery::class);
        $this->app->singleton(McpHttpPolicy::class, static fn (): McpHttpPolicy => new McpHttpPolicy(
            allowedOrigins: static function (): array {
                $origins = config('agent_api.mcp_allowed_origins', []);

                return is_array($origins)
                    ? array_values(array_filter($origins, static fn (mixed $origin): bool => is_string($origin) && $origin !== ''))
                    : [];
            },
            allowedHosts: static function (): array {
                $configured = config('agent_api.mcp_allowed_hosts', []);
                if (is_array($configured) && $configured !== []) {
                    return array_values(array_filter($configured, static fn (mixed $host): bool => is_string($host) && $host !== ''));
                }

                $hosts = [];
                foreach ([config('app.url'), config('bherila-auth.oauth_server.resource')] as $url) {
                    if (! is_string($url)) {
                        continue;
                    }
                    $hosts[] = McpHttpPolicy::hostFromUrl($url);
                }

                return array_values(array_unique($hosts));
            },
            maxRequestBodyBytes: (int) config('agent_api.mcp_max_body_bytes', 262_144),
            maxResponseBodyBytes: (int) config('agent_api.mcp_max_response_body_bytes', 1_048_576),
        ));
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
        Passport::authorizationView('bherila-auth::oauth.authorize');
        HandleCors::skipWhen(fn (Request $request): bool => $request->is('api/v1/mcp') || $request->is('api/v1/genai/*'));
        Event::listen(McpRequestClaimed::class, static function (McpRequestClaimed $event): void {
            GenAiImportJob::query()
                ->where('mcp_request_id', $event->requestId)
                ->where('execution_mode', GenAiImportJob::EXECUTION_EXTERNAL)
                ->where('status', 'pending')
                ->update(['status' => 'processing', 'updated_at' => now()]);
        });
        Event::listen(McpRequestFailed::class, static function (McpRequestFailed $event): void {
            if ($event->terminal) {
                return;
            }
            GenAiImportJob::query()
                ->where('mcp_request_id', $event->requestId)
                ->where('execution_mode', GenAiImportJob::EXECUTION_EXTERNAL)
                ->where('status', 'processing')
                ->update(['status' => 'pending', 'updated_at' => now()]);
        });

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

        RateLimiter::for('agent-api-client-registration', function (Request $request): Limit {
            return Limit::perHour((int) config('agent_api.client_registrations_per_hour', 10))
                ->by(hash('sha256', (string) $request->ip()));
        });
    }
}
