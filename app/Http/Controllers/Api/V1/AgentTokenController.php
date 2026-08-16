<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;

class AgentTokenController extends Controller
{
    public function destroy(Request $request): Response
    {
        $accessToken = $request->user('api')?->token();
        $attributes = $accessToken instanceof AccessToken ? $accessToken->toArray() : [];
        $tokenId = $attributes['oauth_access_token_id'] ?? null;

        if (is_string($tokenId)) {
            $token = Passport::token()->newQuery()->find($tokenId);
            $token?->refreshToken?->revoke();
            $token?->revoke();
        }

        return response()->noContent()->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
