<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Illuminate\Http\Request;

final class OAuthExchangeAccountGuard
{
    private const string REQUEST_ATTRIBUTE = 'oauth_exchange_user_identifier';

    public function recordUserIdentifier(int|string $userIdentifier): void
    {
        app(Request::class)->attributes->set(self::REQUEST_ATTRIBUTE, $userIdentifier);
    }

    /**
     * Recheck the account after Passport has persisted an exchange response.
     *
     * Lifecycle revocation and this post-issuance check cover opposite sides of
     * the disable/issue race: either the new family exists when disablement runs,
     * or this check observes the disabled account and removes the escaped family.
     */
    public function credentialsMayBeReturned(): bool
    {
        $request = app(Request::class);
        $userIdentifier = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $request->attributes->remove(self::REQUEST_ATTRIBUTE);

        if (! is_int($userIdentifier) && ! is_string($userIdentifier)) {
            return true;
        }

        $user = User::query()->find($userIdentifier);

        if ($user instanceof User && $user->canLogin()) {
            return true;
        }

        app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($userIdentifier);

        return false;
    }
}
