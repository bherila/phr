<?php

namespace App\Http\Controllers;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Http\JsonResponse;

class OAuthMetadataController extends Controller
{
    public function authorizationServer(): JsonResponse
    {
        return $this->publicJson([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'scopes_supported' => AgentApiScopes::ids(),
            'resource_indicators_supported' => true,
        ]);
    }

    public function protectedResource(): JsonResponse
    {
        return $this->publicJson([
            'resource' => url('/api/v1'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => AgentApiScopes::ids(),
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function publicJson(array $payload): JsonResponse
    {
        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
