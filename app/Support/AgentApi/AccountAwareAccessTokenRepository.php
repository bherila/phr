<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

final class AccountAwareAccessTokenRepository extends AccessTokenRepository
{
    public function __construct(
        Dispatcher $events,
        private OAuthExchangeAccountGuard $accountGuard,
    ) {
        parent::__construct($events);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $userId = $accessTokenEntity->getUserIdentifier();
        $id = $accessTokenEntity->getIdentifier();
        $validatedGrant = $userId === null ? null : $this->accountGuard->validatedGrantFor($userId);
        $securityVersion = $validatedGrant['security_version']
            ?? ($userId === null ? null : User::query()->whereKey($userId)->value('oauth_security_version'));
        $familyIdentifier = $validatedGrant['family_identifier'] ?? $id;

        Passport::token()->forceFill([
            'id' => $id,
            'user_id' => $userId,
            'client_id' => $clientId = $accessTokenEntity->getClient()->getIdentifier(),
            'scopes' => $accessTokenEntity->getScopes(),
            'revoked' => false,
            'oauth_security_version' => $securityVersion,
            'oauth_family_id' => $familyIdentifier,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ])->save();

        if ($userId !== null) {
            $this->accountGuard->recordIssuedAccessToken($id, $userId);
        }

        $this->events->dispatch(new AccessTokenCreated($id, $userId, $clientId));
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

        $user = User::query()->find($token->user_id);
        if ($user instanceof User
            && $user->canLogin()
            && $token->oauth_security_version !== null
            && (int) $token->oauth_security_version === (int) $user->oauth_security_version) {
            return false;
        }

        app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($token->user_id);

        return true;
    }
}
