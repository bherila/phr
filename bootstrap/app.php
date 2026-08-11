<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        // Unauthenticated /api/* requests must render 401 JSON regardless of the
        // Accept header — never a redirect to /login, and never a 500 from resolving
        // a `login` route that may not exist. API clients treat 401 as "authentication
        // required" and degrade; anything else hits a generic error path. Contract is
        // locked by tests/Feature/ApiUnauthenticatedResponseTest.php.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
