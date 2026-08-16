<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;

class AccountAwareRefreshTokenRepository extends RefreshTokenRepository
{
    public function __construct(
        Dispatcher $events,
        private OAuthExchangeAccountGuard $accountGuard,
    ) {
        parent::__construct($events);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        // SerializeOAuthTokenExchange owns the surrounding transaction. This
        // locking read makes concurrent uses of one refresh token queue until
        // Passport revokes it, so only the first request can issue a successor.
        $refreshToken = Passport::refreshToken()->newQuery()
            ->whereKey($tokenId)
            ->where('revoked', false)
            ->lockForUpdate()
            ->first();

        if ($refreshToken === null) {
            return true;
        }

        $accessToken = Passport::token()->newQuery()->find($refreshToken->access_token_id);
        if ($accessToken?->user_id !== null) {
            $this->accountGuard->recordUserIdentifier($accessToken->user_id);
        }
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
