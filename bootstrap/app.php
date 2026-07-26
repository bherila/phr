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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
