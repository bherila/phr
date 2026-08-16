<?php

use App\Http\Middleware\AuditAgentApiRequest;
use App\Http\Middleware\EnsureOAuthAuthorizationUserCanLogin;
use App\Http\Middleware\ThrottleAgentApiAuthentication;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Agent API audits must wrap throttling so rejected 429 attempts retain the
        // same metadata-only evidence as successful authenticated requests.
        $middleware->prependToPriorityList(ThrottleRequests::class, AuditAgentApiRequest::class);
        $middleware->append(ThrottleAgentApiAuthentication::class);
        // Passport's authorization routes declare their package middleware
        // outside the route-level web/auth middleware. Force the account-state
        // check after session authentication but before the consent controller.
        $middleware->appendToPriorityList(Authenticate::class, EnsureOAuthAuthorizationUserCanLogin::class);

        // PHR respiratory-events / Sinus Sentinel device ingest authenticates via bearer
        // token (AuthenticateWebOrMcpRequest) and carries no session/CSRF token. These
        // routes still sit in the `web` group for session support, so exempt the write
        // paths from CSRF. Each exempted path's FormRequest must reject non-JSON bodies
        // with 415 — that pairing is what closes the CSRF gap (a cross-site form post
        // cannot send application/json past CORS preflight).
        $middleware->validateCsrfTokens(except: [
            'api/phr/patients/*/respiratory-events/batch',
            'api/phr/patients/*/respiratory-events/flag-batch',
            'api/phr/patients/*/sinus-settings',
            'api/phr/patients/*/sinus-enrollments/batch',
            // Device-pairing exchange (DevicePairingExchangeController): the Mac
            // app has no session/cookie jar, so it cannot carry a CSRF token.
            // DevicePairingExchangeRequest's 415 guard on non-JSON bodies is what
            // closes the CSRF gap this exemption would otherwise open.
            'api/device-pairing/exchange',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            return response()->json(
                ['message' => 'Unauthenticated.'],
                401,
                [
                    'Cache-Control' => 'private, no-store',
                    'WWW-Authenticate' => sprintf(
                        'Bearer resource_metadata="%s"',
                        url('/.well-known/oauth-protected-resource/api/v1'),
                    ),
                ],
            );
        });

        // Unauthenticated /api/* requests must render 401 JSON regardless of the
        // Accept header — never a redirect to /login, and never a 500 from resolving
        // a `login` route that may not exist. API clients treat 401 as "authentication
        // required" and degrade; anything else hits a generic error path. Contract is
        // locked by tests/Feature/ApiUnauthenticatedResponseTest.php.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
