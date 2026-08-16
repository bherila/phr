<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;

class AccountAwareAuthCodeRepository extends AuthCodeRepository
{
    public function isAuthCodeRevoked(string $codeId): bool
    {
        $authorizationCode = Passport::authCode()->newQuery()
            ->whereKey($codeId)
            ->where('revoked', false)
            ->first();

        if ($authorizationCode === null) {
            return true;
        }

        $user = User::query()->find($authorizationCode->user_id);

        if (! $user instanceof User || ! $user->canLogin()) {
            app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($authorizationCode->user_id);

            return true;
        }

        return false;
    }
}
