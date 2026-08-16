<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
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
            'mcp_url' => url('/api/v1/mcp'),
            'oauth' => [
                'authorization_server_metadata' => url('/.well-known/oauth-authorization-server'),
                'protected_resource_metadata' => url('/.well-known/oauth-protected-resource/api/v1'),
                'authorization_code_pkce' => true,
                'refresh_token_rotation' => true,
                'access_token_ttl_seconds' => 900,
            ],
            'scopes' => AgentApiScopes::descriptions(),
            'limits' => [
                'requests_per_minute' => 120,
                'authentication_attempts_per_minute' => config('agent_api.authentication_attempts_per_minute', 300),
                'token_exchange_attempts_per_minute' => config('agent_api.token_exchange_attempts_per_minute', 60),
                'authorization_attempts_per_minute' => config('agent_api.authorization_attempts_per_minute', 30),
                'client_registrations_per_hour' => config('agent_api.client_registrations_per_hour', 10),
                'mcp_max_body_bytes' => config('agent_api.mcp_max_body_bytes', 262_144),
                'mcp_session_ttl_seconds' => config('agent_api.mcp_session_ttl_seconds', 1800),
                'default_page_size' => 25,
                'maximum_page_size' => 100,
            ],
            'operations' => [
                'capabilities.get' => ['available' => true, 'scope' => null],
                'identity.get' => ['available' => true, 'scope' => AgentApiScopes::IDENTITY_READ],
                'patients.list' => ['available' => true, 'scope' => AgentApiScopes::PATIENTS_READ],
                'patients.get' => ['available' => true, 'scope' => AgentApiScopes::PATIENTS_READ],
                'clinical.list' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'clinical.get' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'clinical.upsert' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_WRITE],
                'records.search' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'timeline.list' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'eobs.list' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'eobs.get' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'eob_lines.list' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'eob_lines.get' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'evidence.links' => ['available' => true, 'scope' => AgentApiScopes::CLINICAL_READ],
                'documents.list' => ['available' => true, 'scope' => AgentApiScopes::DOCUMENTS_READ],
                'documents.get' => ['available' => true, 'scope' => AgentApiScopes::DOCUMENTS_READ],
                'documents.download_access.create' => ['available' => true, 'scope' => AgentApiScopes::DOCUMENTS_READ],
                'documents.download' => ['available' => true, 'scope' => AgentApiScopes::DOCUMENTS_READ],
                'mcp.connect' => ['available' => true, 'scope' => AgentApiScopes::MCP_USE],
                'mcp.exchange' => ['available' => true, 'scope' => AgentApiScopes::MCP_USE],
                'mcp.session.delete' => ['available' => true, 'scope' => AgentApiScopes::MCP_USE],
                'oauth.disconnect' => ['available' => true, 'scope' => null],
            ],
            'clinical_resources' => AgentClinicalResourceCatalog::ids(),
            'writable_clinical_resources' => AgentClinicalResourceCatalog::writableIds(),
            'list_filters' => [
                'common' => ['limit', 'cursor', 'updated_after', 'updated_before'],
                'clinical_provenance' => ['import_source', 'source_document_id'],
                'archival' => ['archived'],
                'unified_records' => [
                    'resource_type', 'q', 'date_from', 'date_to', 'provider', 'facility',
                    'code', 'source', 'review_status', 'updated_after', 'updated_before',
                ],
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
