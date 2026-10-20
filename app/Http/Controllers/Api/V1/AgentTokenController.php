<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AgentApi\OAuthCredentialRevoker;
use App\Support\AgentApi\OAuthPatientConsents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;

class AgentTokenController extends Controller
{
    public function __construct(private OAuthCredentialRevoker $credentialRevoker) {}

    public function destroy(Request $request): Response
    {
        $accessToken = $request->user('api')?->token();
        $attributes = $accessToken instanceof AccessToken ? $accessToken->toArray() : [];
        $tokenId = $attributes['oauth_access_token_id'] ?? null;

        if (is_string($tokenId)) {
            $connectionName = config('passport.connection');
            DB::connection(is_string($connectionName) ? $connectionName : null)
                ->transaction(function () use ($tokenId): void {
                    $presentedToken = Passport::token()->newQuery()
                        ->whereKey($tokenId)
                        ->first();
                    if ($presentedToken === null) {
                        return;
                    }

                    $this->credentialRevoker->revokeFamilyForAccessToken($presentedToken);
                }, 1);
        }

        return $this->revoked();
    }

    /**
     * Disconnect the calling client: withdraw the person's consent for it and
     * revoke every code and token it holds for them, so reconnecting shows the
     * consent screen again.
     */
    public function revokeConsent(Request $request): Response
    {
        $accessToken = $request->user('api')?->token();
        $attributes = $accessToken instanceof AccessToken ? $accessToken->toArray() : [];
        $userId = $attributes['oauth_user_id'] ?? null;
        $clientId = $attributes['oauth_client_id'] ?? null;

        if (! (is_string($userId) || is_int($userId)) || ! is_string($clientId) || $clientId === '') {
            return $this->revoked();
        }

        // A personal API token has no consent to withdraw, and its client is
        // shared by every personal token the person holds: it only disconnects
        // itself, exactly as the token endpoint's DELETE does.
        $client = Passport::client()->newQuery()->find($clientId);
        if ($client === null || ! $client->hasGrantType('authorization_code')) {
            return $this->destroy($request);
        }

        $this->credentialRevoker->revokeClientConsent($userId, $clientId);

        return $this->revoked();
    }

    public function revokePatientConsent(Request $request, string $patient_public_id): Response
    {
        app(OAuthPatientConsents::class)->revoke($request, $patient_public_id);

        return $this->revoked();
    }

    private function revoked(): Response
    {
        return response()->noContent()->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
