<?php

namespace App\Http\Controllers;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\ClientRepository;

final class OAuthDynamicClientRegistrationController extends Controller
{
    private const int MAX_BODY_BYTES = 16_384;

    public function __invoke(Request $request, ClientRepository $clients): JsonResponse
    {
        if (! $request->isJson() || strlen((string) $request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->invalid('Client registration requires a bounded JSON request.');
        }

        $validator = Validator::make($request->all(), [
            'client_name' => ['required', 'string', 'min:1', 'max:100'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => ['required', 'string', 'max:2048'],
            'grant_types' => ['sometimes', 'array', 'size:2'],
            'grant_types.*' => ['string', 'in:authorization_code,refresh_token'],
            'response_types' => ['sometimes', 'array', 'size:1'],
            'response_types.*' => ['string', 'in:code'],
            'token_endpoint_auth_method' => ['sometimes', 'string', 'in:none'],
            'scope' => ['sometimes', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return $this->invalid();
        }

        $metadata = $validator->validated();
        $grantTypes = array_values(array_unique($metadata['grant_types'] ?? ['authorization_code', 'refresh_token']));
        sort($grantTypes);
        if ($grantTypes !== ['authorization_code', 'refresh_token']) {
            return $this->invalid();
        }
        $requestedScopes = null;
        if (isset($metadata['scope'])) {
            $requestedScopes = array_values(array_unique(
                preg_split('/\s+/', trim((string) $metadata['scope'])) ?: [],
            ));
            if (array_diff($requestedScopes, AgentApiScopes::ids()) !== []) {
                return $this->invalid();
            }
        }

        $clientName = trim((string) $metadata['client_name']);
        if ($clientName === '' || preg_match('/[\p{C}]/u', $clientName) === 1) {
            return $this->invalid();
        }

        $redirectUris = [];
        foreach ($metadata['redirect_uris'] as $redirectUri) {
            if (! is_string($redirectUri) || ! $this->validRedirectUri($redirectUri)) {
                return $this->invalid();
            }
            $redirectUris[] = $redirectUri;
        }
        $redirectUris = array_values(array_unique($redirectUris));
        if ($redirectUris === []) {
            return $this->invalid();
        }

        $client = $clients->createAuthorizationCodeGrantClient(
            $clientName,
            $redirectUris,
            confidential: false,
        );
        $client->forceFill([
            'dynamically_registered_at' => now(),
            'scopes' => $requestedScopes,
        ])->save();

        $responseMetadata = [
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        if ($requestedScopes !== null) {
            $responseMetadata['scope'] = implode(' ', $requestedScopes);
        }

        return response()->json($responseMetadata, 201, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function validRedirectUri(string $redirectUri): bool
    {
        if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($redirectUri);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    private function invalid(string $description = 'Client metadata is invalid.'): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_client_metadata',
            'error_description' => $description,
        ], 400, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }
}
