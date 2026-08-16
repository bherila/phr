<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;

class AccountAwareAuthCodeRepository extends AuthCodeRepository
{
    public function __construct(private OAuthExchangeAccountGuard $accountGuard) {}

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

        $this->accountGuard->recordUserIdentifier($authorizationCode->user_id);
        $user = User::query()->find($authorizationCode->user_id);

        if (! $user instanceof User || ! $user->canLogin()) {
            app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($authorizationCode->user_id);

            return true;
        }

        return false;
    }
}
