<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

class AccountAwareRefreshTokenRepository extends RefreshTokenRepository
{
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $refreshToken = Passport::refreshToken()->newQuery()
            ->whereKey($tokenId)
            ->where('revoked', false)
            ->first();

        if ($refreshToken === null) {
            return true;
        }

        $accessToken = Passport::token()->newQuery()->find($refreshToken->access_token_id);
        $user = $accessToken?->user_id === null
            ? null
            : User::query()->find($accessToken->user_id);

        if (! $user instanceof User || ! $user->canLogin()) {
            if ($accessToken?->user_id !== null) {
                app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($accessToken->user_id);
            } else {
                $refreshToken->revoke();
                $accessToken?->revoke();
            }

            return true;
        }

        return false;
    }
}
