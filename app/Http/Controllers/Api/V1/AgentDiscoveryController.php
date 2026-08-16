<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;

class AgentDiscoveryController extends Controller
{
    public function capabilities(): JsonResponse
    {
        return response()->json([
            'api_version' => 'v1',
            'openapi_url' => url('/openapi/phr-agent-v1.json'),
            'oauth' => [
                'authorization_server_metadata' => url('/.well-known/oauth-authorization-server'),
                'protected_resource_metadata' => url('/.well-known/oauth-protected-resource'),
                'authorization_code_pkce' => true,
                'refresh_token_rotation' => true,
                'access_token_ttl_seconds' => 900,
            ],
            'scopes' => AgentApiScopes::descriptions(),
            'limits' => [
                'requests_per_minute' => 120,
                'default_page_size' => 25,
                'maximum_page_size' => 100,
            ],
            'operations' => [
                'identity.get' => ['available' => true, 'scope' => AgentApiScopes::IDENTITY_READ],
                'oauth.disconnect' => ['available' => true, 'scope' => AgentApiScopes::IDENTITY_READ],
                'patients.list' => ['available' => false, 'scope' => AgentApiScopes::PATIENTS_READ],
                'patients.get' => ['available' => false, 'scope' => AgentApiScopes::PATIENTS_READ],
                'records.search' => ['available' => false, 'scope' => AgentApiScopes::CLINICAL_READ],
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $token = $user?->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $scopes = $attributes['oauth_scopes'] ?? [];

        return response()->json([
            'identity' => [
                'id' => $user?->getAuthIdentifier(),
                'name' => $user?->name,
                'email' => $user?->email,
            ],
            'scopes' => is_array($scopes) ? array_values($scopes) : [],
        ])->withHeaders($this->privateHeaders());
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
