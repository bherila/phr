<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport always enables the client_credentials grant. PHR registers clients
 * for the authorization-code and refresh grants only, and Passport checks a
 * client's grant list, so a client secret alone cannot mint a userless token
 * today. This refuses the grant at the token endpoint anyway: every PHR
 * credential acts for a person, and that must not depend on how each client
 * row happens to be registered.
 */
final class RefuseClientCredentialsGrant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passport.token') && $request->input('grant_type') === 'client_credentials') {
            return new JsonResponse([
                'error' => 'unsupported_grant_type',
                'error_description' => 'The authorization grant type is not supported by the authorization server.',
            ], 400, [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
        }

        return $next($request);
    }
}
