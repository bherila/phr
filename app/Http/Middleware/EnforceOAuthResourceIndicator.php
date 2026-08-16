<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthResourceIndicator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class EnforceOAuthResourceIndicator
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->routeIs('passport.authorizations.authorize')) {
            $resource = $request->query('resource');
            $scopes = preg_split('/\s+/', trim((string) $request->query('scope', ''))) ?: [];
            if (($resource !== null && ! OAuthResourceIndicator::isAgentApi($resource))
                || (in_array(AgentApiScopes::MCP_USE, $scopes, true) && $resource === null)) {
                return $this->invalidResource();
            }
            if ($resource !== null) {
                $request->attributes->set(
                    OAuthResourceIndicator::REQUEST_ATTRIBUTE,
                    OAuthResourceIndicator::agentApi(),
                );
            }

            $response = $next($request);
            $authToken = $request->session()->get('authToken');
            if (is_string($authToken) && $resource !== null) {
                Cache::put($this->cacheKey($authToken), OAuthResourceIndicator::agentApi(), now()->addMinutes(10));
            }

            return $response;
        }

        if ($request->routeIs('passport.authorizations.approve', 'passport.authorizations.deny')) {
            $authToken = $request->input('auth_token');
            $resource = is_string($authToken) ? Cache::pull($this->cacheKey($authToken)) : null;
            if (is_string($resource)) {
                $request->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $resource);
            }
        }

        if ($request->routeIs('passport.token')) {
            $resource = $request->input('resource');
            if ($resource !== null && ! OAuthResourceIndicator::isAgentApi($resource)) {
                return $this->invalidResource();
            }
        }

        return $next($request);
    }

    private function invalidResource(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'invalid_target',
            'error_description' => 'The requested resource is invalid.',
        ], 400, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    private function cacheKey(string $authToken): string
    {
        return 'oauth-resource:'.hash('sha256', $authToken);
    }
}
