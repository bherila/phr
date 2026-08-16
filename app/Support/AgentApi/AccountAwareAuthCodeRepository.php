<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

class AccountAwareAuthCodeRepository extends AuthCodeRepository
{
    public function __construct(private OAuthExchangeAccountGuard $accountGuard) {}

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $userId = $authCodeEntity->getUserIdentifier();
        $securityVersion = $userId === null
            ? null
            : User::query()->whereKey($userId)->value('oauth_security_version');

        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $userId,
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => false,
            'oauth_security_version' => $securityVersion,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ])->save();
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        // The token-exchange middleware holds this lock through issuance and
        // Passport's final revoke, preserving one-time authorization-code use.
        $authorizationCode = Passport::authCode()->newQuery()
            ->whereKey($codeId)
            ->where('revoked', false)
            ->lockForUpdate()
            ->first();

        if ($authorizationCode === null) {
            return true;
        }

        $user = User::query()->find($authorizationCode->user_id);

        if (! $user instanceof User
            || ! $user->canLogin()
            || $authorizationCode->oauth_security_version === null
            || (int) $authorizationCode->oauth_security_version !== (int) $user->oauth_security_version) {
            app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($authorizationCode->user_id);

            return true;
        }

        $this->accountGuard->recordValidatedGrant(
            $authorizationCode->user_id,
            (int) $authorizationCode->oauth_security_version,
            null,
        );

        return false;
    }
}
