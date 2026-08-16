<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthAuthorizationStateStore;
use App\Support\AgentApi\OAuthResourceIndicator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

final class EnforceOAuthResourceIndicator
{
    public function __construct(private OAuthAuthorizationStateStore $authorizationState) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->routeIs('passport.authorizations.authorize')) {
            $resource = $request->query('resource');
            $scopes = array_values(array_filter(
                preg_split('/\s+/', trim((string) $request->query('scope', ''))) ?: [],
                static fn (string $scope): bool => $scope !== '',
            ));
            $clientId = $request->query('client_id');
            $client = is_string($clientId) ? Passport::client()->newQuery()->find($clientId) : null;
            if ($client !== null
                && $client->dynamically_registered_at !== null
                && array_filter($scopes, static fn (string $scope): bool => ! $client->hasScope($scope)) !== []) {
                return $this->invalidScope();
            }
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
                $this->authorizationState->rememberResource(
                    $authToken,
                    OAuthResourceIndicator::agentApi(),
                );
            }

            return $response;
        }

        if ($request->routeIs('passport.authorizations.approve', 'passport.authorizations.deny')) {
            $authToken = $request->input('auth_token');
            $resource = is_string($authToken) ? $this->authorizationState->pullResource($authToken) : null;
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

    private function invalidScope(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'invalid_scope',
            'error_description' => 'The requested scope is invalid for this client.',
        ], 400, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }
}
