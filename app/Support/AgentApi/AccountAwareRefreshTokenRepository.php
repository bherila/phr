<?php

namespace App\Support\AgentApi;

use App\Models\User;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

class AccountAwareRefreshTokenRepository extends RefreshTokenRepository
{
    public function __construct(
        Dispatcher $events,
        private OAuthExchangeAccountGuard $accountGuard,
    ) {
        parent::__construct($events);
    }

    /**
     * Passport's persistence, plus the owner record the auth package keeps on a
     * refresh token, so OAuthCredentialOwners::revokeAll() finds it by owner.
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $model = Passport::refreshToken();
        $accessToken = $refreshTokenEntity->getAccessToken();
        $attributes = [
            'id' => $id = $refreshTokenEntity->getIdentifier(),
            'access_token_id' => $accessTokenId = $accessToken->getIdentifier(),
            'revoked' => false,
            'expires_at' => $refreshTokenEntity->getExpiryDateTime(),
        ];
        $owner = $accessToken->getUserIdentifier();
        if ($owner !== null && $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), ProviderIdentityTokens::OWNER_COLUMN)) {
            $attributes[ProviderIdentityTokens::OWNER_COLUMN] = (string) $owner;
        }
        $model->forceFill($attributes)->save();

        $this->events->dispatch(new RefreshTokenCreated($id, $accessTokenId));
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

        if (! $user instanceof User || ! $user->mayHoldOAuthCredentials()) {
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

        $storedResource = is_string($accessToken->resource_uri) ? $accessToken->resource_uri : null;
        // Refreshing names the grant's own resource, or omits it and means the
        // REST resource. A mismatch is refused without consuming the refresh
        // token, so a client can retry with the resource it was granted for.
        $requestedResource = OAuthResourceIndicator::requestNamesResource(request())
            ? OAuthResourceIndicator::requestResource(request())
            : $storedResource;
        if ($requestedResource !== $storedResource) {
            return true;
        }
        // A scope ceiling tightened since the grant (an MCP connection scope on a
        // credential bound to the REST resource) retires the family's renewal.
        if ($storedResource !== null && ! OAuthResourceIndicator::scopesAllowedFor($storedResource, $accessToken->scopes)) {
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
