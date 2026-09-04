<?php

namespace App\Support\AgentApi;

use App\Models\OAuthTokenFamily;
use App\Models\User;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ResourceAccessToken;
use BWH\Auth\OAuth\Server\ResourceAccessTokenRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;

final class AccountAwareAccessTokenRepository extends AccessTokenRepository
{
    public function __construct(
        Dispatcher $events,
        private ResourceAccessTokenRepository $resourceTokens,
        private OAuthExchangeAccountGuard $accountGuard,
    ) {
        parent::__construct($events);
    }

    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null,
    ): AccessTokenEntityInterface {
        $token = $this->resourceTokens->getNewToken($clientEntity, $scopes, $userIdentifier);
        $validatedGrant = $userIdentifier === null
            ? null
            : $this->accountGuard->validatedGrantFor($userIdentifier);
        $resource = $validatedGrant['resource_uri'] ?? null;
        if ($token instanceof ResourceAccessToken && is_string($resource)) {
            $token->setResource($resource);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        DB::transaction(function () use ($accessTokenEntity): void {
            $userId = $accessTokenEntity->getUserIdentifier();
            $id = $accessTokenEntity->getIdentifier();
            $validatedGrant = $userId === null ? null : $this->accountGuard->validatedGrantFor($userId);
            $securityVersion = $validatedGrant['security_version']
                ?? ($userId === null ? null : User::query()->whereKey($userId)->value('oauth_security_version'));
            $familyIdentifier = $validatedGrant['family_identifier'] ?? $id;
            $family = OAuthTokenFamily::query()->firstOrCreate([
                'id' => $familyIdentifier,
            ], [
                'user_id' => $userId,
                'client_id' => $accessTokenEntity->getClient()->getIdentifier(),
                'oauth_security_version' => $securityVersion,
                'revoked' => false,
                'expires_at' => now()->addDays(AgentApiTokenPolicy::REFRESH_TOKEN_LIFETIME_DAYS),
            ]);
            $familyMatchesGrant = (string) $family->user_id === (string) $userId
                && (string) $family->client_id === (string) $accessTokenEntity->getClient()->getIdentifier()
                && (int) $family->oauth_security_version === (int) $securityVersion;
            if ($familyMatchesGrant && ! $family->revoked) {
                $family->forceFill([
                    'expires_at' => now()->addDays(AgentApiTokenPolicy::REFRESH_TOKEN_LIFETIME_DAYS),
                ])->save();
            }

            $resource = $validatedGrant['resource_uri'] ?? null;
            if (is_string($resource)) {
                request()->attributes->set(OAuthResourceIndicator::REQUEST_ATTRIBUTE, $resource);
            }
            $this->resourceTokens->persistNewAccessToken($accessTokenEntity);
            Passport::token()->newQuery()->whereKey($id)->update([
                'revoked' => ! $familyMatchesGrant || $family->revoked,
                'oauth_security_version' => $securityVersion,
                'oauth_family_id' => $familyIdentifier,
            ]);

            if ($userId !== null) {
                $this->accountGuard->recordIssuedAccessToken($id, $userId);
            }
        });
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $token = Passport::token()->newQuery()
            ->whereKey($tokenId)
            ->where('revoked', false)
            ->first();

        if ($token === null) {
            return true;
        }

        if ($token->user_id === null) {
            return false;
        }

        $familyIdentifier = is_string($token->oauth_family_id) ? $token->oauth_family_id : $token->id;
        if (OAuthTokenFamily::query()->whereKey($familyIdentifier)->where('revoked', true)->exists()) {
            app(OAuthCredentialRevoker::class)->revokeFamilyForAccessToken($token);

            return true;
        }

        $user = User::query()->find($token->user_id);
        if (! $user instanceof User || ! $user->canLogin()) {
            app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($token->user_id);

            return true;
        }

        if ($token->oauth_security_version === null
            || (int) $token->oauth_security_version !== (int) $user->oauth_security_version) {
            app(OAuthCredentialRevoker::class)->revokeFamilyForAccessToken($token);

            return true;
        }

        return $this->resourceTokens->isAccessTokenRevoked($tokenId);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->resourceTokens->revokeAccessToken($tokenId);
    }
}
