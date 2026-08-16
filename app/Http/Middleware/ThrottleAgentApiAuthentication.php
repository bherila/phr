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
        if ($request->is('oauth/authorize')) {
            return $this->throttle->handle($request, $next, 'agent-api-authorization');
        }

        if ($request->isMethod('POST') && $request->is('oauth/token')) {
            return $this->throttle->handle($request, $next, 'agent-api-token-exchange');
        }

        if (! $request->is('api/v1/*') || $request->is('api/v1/capabilities')) {
            return $next($request);
        }

        // This wrapper is intentionally a distinct middleware class. Laravel's
        // route priority moves ThrottleRequests behind Authenticate, while this
        // global boundary rejects saturated IP buckets before routing invokes
        // Passport for another malformed bearer token or grant exchange.
        return $this->throttle->handle($request, $next, 'agent-api-authentication');
    }
}
