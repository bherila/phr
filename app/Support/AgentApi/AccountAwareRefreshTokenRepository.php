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
        if ($owner !== null && ProviderIdentityStamp::hasColumns($model)) {
            // The owner and the provider stamp of the access token it was issued
            // with, so the refresh token stays checkable once that row is purged.
            $issuedWith = Passport::token()->newQuery()->find($accessTokenId);
            $attributes[ProviderIdentityTokens::OWNER_COLUMN] = (string) $owner;
            $attributes[ProviderIdentityTokens::SUBJECT_COLUMN] = $issuedWith?->getAttribute(ProviderIdentityTokens::SUBJECT_COLUMN);
            $attributes[ProviderIdentityTokens::GENERATION_COLUMN] = $issuedWith?->getAttribute(ProviderIdentityTokens::GENERATION_COLUMN);
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
        // resource the grant was made for. A mismatch is refused without consuming the refresh
        // token, so a client can retry with the resource it was granted for.
        $requestedResource = OAuthResourceIndicator::exchangeResource(request(), $storedResource);
        if ($requestedResource !== $storedResource) {
            return true;
        }
        // A scope ceiling tightened since the grant (an MCP connection scope on a
        // credential bound to the REST resource) retires the family's renewal.
        if ($storedResource !== null && ! OAuthResourceIndicator::scopesAllowedFor($storedResource, $accessToken->scopes)) {
            return true;
        }

        // Renewal checks the person freshly against the grant's provider stamp
        // and hands it to the new token (a no-op until enforcement is enabled).
        // An unavailable provider throws before anything is consumed.
        $providerIdentity = app(ProviderIdentityTokens::class);
        $stamped = $refreshToken->getAttribute(ProviderIdentityTokens::OWNER_COLUMN) !== null ? $refreshToken : $accessToken;
        if ($providerIdentity->revoked($stamped, fresh: true)) {
            return true;
        }
        $providerIdentity->carry(request(), $stamped);

        $this->accountGuard->recordValidatedGrant(
            $accessToken->user_id,
            (int) $accessToken->oauth_security_version,
            is_string($accessToken->oauth_family_id) ? $accessToken->oauth_family_id : $accessToken->id,
            $storedResource,
        );

        return false;
    }
}
