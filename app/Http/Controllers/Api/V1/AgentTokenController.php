<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
            $connectionName = config('passport.connection');
            DB::connection(is_string($connectionName) ? $connectionName : null)
                ->transaction(function () use ($tokenId): void {
                    // Refresh exchange locks the presented refresh row first. Use
                    // the same order so either mutation wins, and a disconnect
                    // waiting behind rotation can see and revoke its successor.
                    Passport::refreshToken()->newQuery()
                        ->where('access_token_id', $tokenId)
                        ->lockForUpdate()
                        ->get()
                        ->each->revoke();

                    $presentedToken = Passport::token()->newQuery()
                        ->whereKey($tokenId)
                        ->lockForUpdate()
                        ->first();
                    if ($presentedToken === null) {
                        return;
                    }

                    $familyIdentifier = is_string($presentedToken->oauth_family_id)
                        ? $presentedToken->oauth_family_id
                        : $presentedToken->id;
                    $familyTokens = Passport::token()->newQuery()
                        ->where('user_id', $presentedToken->user_id)
                        ->where('client_id', $presentedToken->client_id)
                        ->where(function ($query) use ($familyIdentifier, $tokenId): void {
                            $query->where('oauth_family_id', $familyIdentifier)->orWhere('id', $tokenId);
                        })
                        ->lockForUpdate()
                        ->get();
                    $familyTokenIds = $familyTokens->pluck('id');

                    Passport::refreshToken()->newQuery()
                        ->whereIn('access_token_id', $familyTokenIds)
                        ->lockForUpdate()
                        ->get()
                        ->each->revoke();
                    $familyTokens->each->revoke();
                }, 1);
        }

        return response()->noContent()->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
