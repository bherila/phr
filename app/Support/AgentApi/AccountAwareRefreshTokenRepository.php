<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
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
        $connection = config('passport.connection');

        return DB::connection(is_string($connection) ? $connection : null)
            ->transaction(fn (): bool => $this->refreshTokenIsRevoked($tokenId), 1);
    }

    private function refreshTokenIsRevoked(string $tokenId): bool
    {
        // Resolve the stable family before taking locks. Every family mutation
        // locks that root first, then the presented refresh row, preventing a
        // concurrent successor from escaping reuse detection or disconnect.
        $refreshToken = Passport::refreshToken()->newQuery()->find($tokenId);

        if ($refreshToken === null) {
            return true;
        }

        $accessToken = Passport::token()->newQuery()->find($refreshToken->access_token_id);
        if ($accessToken === null) {
            $refreshToken->forceFill(['revoked' => true])->save();

            return true;
        }

        $revoker = app(OAuthCredentialRevoker::class);
        $revoker->lockFamilyForAccessToken($accessToken);
        $refreshToken = Passport::refreshToken()->newQuery()
            ->whereKey($tokenId)
            ->lockForUpdate()
            ->first();
        if ($refreshToken === null) {
            return true;
        }

        $accessToken = Passport::token()->newQuery()->find($refreshToken->access_token_id);
        if ($accessToken === null) {
            $refreshToken->forceFill(['revoked' => true])->save();

            return true;
        }

        if ($refreshToken->revoked) {
            $revoker->revokeFamilyForAccessToken($accessToken);

            return true;
        }

        $user = $accessToken->user_id === null
            ? null
            : User::query()->find($accessToken->user_id);

        if (! $user instanceof User || ! $user->canLogin()) {
            if ($accessToken->user_id !== null) {
                $revoker->revokeForUserIdentifier($accessToken->user_id);
            } else {
                $refreshToken->revoke();
                $accessToken->revoke();
            }

            return true;
        }

        if ($accessToken->revoked
            || $accessToken->oauth_security_version === null
            || (int) $accessToken->oauth_security_version !== (int) $user->oauth_security_version) {
            $revoker->revokeFamilyForAccessToken($accessToken);

            return true;
        }

        $requestedResource = request()->input('resource');
        $requestedResource = $requestedResource === null
            ? null
            : OAuthResourceIndicator::canonicalize($requestedResource);
        $storedResource = is_string($accessToken->resource_uri) ? $accessToken->resource_uri : null;
        if ($requestedResource !== $storedResource) {
            $revoker->revokeFamilyForAccessToken($accessToken);

            return true;
        }

        $this->accountGuard->recordValidatedGrant(
            $accessToken->user_id,
            (int) $accessToken->oauth_security_version,
            is_string($accessToken->oauth_family_id) ? $accessToken->oauth_family_id : $accessToken->id,
            $storedResource,
        );

        return false;
    }
}
