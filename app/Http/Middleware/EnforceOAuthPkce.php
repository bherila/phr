<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceOAuthPkce
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->routeIs('passport.authorizations.authorize')) {
            $challenge = $request->query('code_challenge');
            $method = $request->query('code_challenge_method');

            if (! is_string($challenge) || $challenge === '' || $method !== 'S256') {
                // Do not redirect an unvalidated redirect_uri. A local, generic OAuth
                // error avoids turning this endpoint into an open redirect and never
                // repeats caller-controlled values into the response or logs.
                return new JsonResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'Authorization requests require S256 PKCE.',
                ], 400, [
                    'Cache-Control' => 'no-store',
                    'Pragma' => 'no-cache',
                ]);
            }
        }

        return $next($request);
    }
}
