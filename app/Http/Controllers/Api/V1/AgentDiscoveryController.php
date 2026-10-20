<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PHR\DICOM\DicomUploadLimits;
use App\Support\AgentApi\AgentApiPrincipal;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentOpenApi;
use App\Support\AgentApi\AgentOperations;
use App\Support\AgentApi\OAuthPatientConsents;
use App\Support\PHR\PhrDocumentUploadLimits;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;

class AgentDiscoveryController extends Controller
{
    /**
     * The contract, generated from the operation registry and addressed to
     * this installation: its API base URL and OAuth endpoints come from
     * configuration, so a connector importing the document needs nothing typed
     * by hand.
     */
    public function openApi(): JsonResponse
    {
        return response()->json(AgentOpenApi::forInstallation()->full(), 200, ['Cache-Control' => 'max-age=300, public'], JSON_UNESCAPED_SLASHES);
    }

    public function capabilities(AgentOperations $operations): JsonResponse
    {
        return response()->json([
            'api_version' => 'v1',
            'openapi_url' => url('/api/openapi.json'),
            'mcp_url' => url('/api/v1/mcp'),
            'oauth' => [
                'billing_patient_consent' => OAuthPatientConsents::CAPABILITY,
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
                'maximum_document_upload_bytes' => PhrDocumentUploadLimits::MAX_BYTES,
                'maximum_dicom_upload_file_bytes' => DicomUploadLimits::maxMultipartFileBytes(),
            ],
            'workflow' => [
                'patient_selection' => [
                    'first' => 'identity.get',
                    'first_scope' => AgentApiScopes::IDENTITY_READ,
                    'enumerate' => 'patients.list',
                    'enumerate_scope' => AgentApiScopes::PATIENTS_READ,
                    'confirm' => 'patients.get',
                    'confirm_scope' => AgentApiScopes::PATIENTS_READ,
                    'rule' => 'Never infer or guess a patient id from an external record; use an id returned by patients.list and confirm it before a write.',
                ],
                'clinical_write' => [
                    'read_before_write' => true,
                    'stable_external_id_required' => true,
                    'source_document_link_recommended' => true,
                    'existing_record_version_required' => true,
                    'retry_outcome' => 'unchanged',
                ],
                'oauth' => [
                    'discovery' => url('/.well-known/oauth-authorization-server'),
                    'authorization_code_pkce_method' => 'S256',
                    'resource_metadata' => url('/.well-known/oauth-protected-resource/api/v1/mcp'),
                ],
            ],
            'operations' => $operations->capabilities(),
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
                'change_feed' => ['limit', 'cursor', 'resource_type', 'updated_after', 'watermark'],
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function me(Request $request, AgentOperations $operations): JsonResponse
    {
        $user = $request->user('api');
        $token = $user?->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $scopes = $attributes['oauth_scopes'] ?? [];
        $report = $operations->availability()->evaluate(new AgentApiPrincipal($request));

        return response()->json([
            'identity' => [
                'id' => $user?->getAuthIdentifier(),
                'name' => $user?->name,
                'email' => $user?->email,
                'provider_subject' => $this->providerSubject($user),
            ],
            'scopes' => is_array($scopes) ? array_values($scopes) : [],
            'operations' => [
                'available' => $report->availableIds(),
                'withheld' => $report->withheldArray(),
            ],
        ])->withHeaders($this->privateHeaders());
    }

    /**
     * The caller's subject at the trusted identity provider, so a connecting
     * application can check it reached the account of the person who
     * connected. A stable cross-application identifier rather than a secret,
     * but kept to this authorized self-description: never in a public
     * response, a refusal or a log. Null for an account with no provider
     * binding, or a binding the trusted provider does not vouch for.
     */
    private function providerSubject(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }
        try {
            return app(ProviderBindingResolver::class)->binding($user)?->subject;
        } catch (ProviderSessionExpired) {
            return null;
        }
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
