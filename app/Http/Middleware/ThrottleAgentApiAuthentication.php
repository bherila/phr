<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleAgentApiAuthentication
{
    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/me', 'api/v1/oauth/token')) {
            return $next($request);
        }

        // This wrapper is intentionally a distinct middleware class. Laravel's
        // route priority moves ThrottleRequests behind Authenticate, while this
        // global boundary rejects a saturated IP bucket before routing invokes
        // Passport for another malformed or revoked bearer token.
        return $this->throttle->handle($request, $next, 'agent-api-authentication');
    }
}
