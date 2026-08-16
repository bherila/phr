<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AgentApi\OAuthCredentialRevoker;
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

        return response()->noContent()->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
