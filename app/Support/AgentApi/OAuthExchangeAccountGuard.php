<?php

namespace App\Support\AgentApi;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;

final class OAuthExchangeAccountGuard
{
    private const string REQUEST_ATTRIBUTE = 'oauth_exchange_user_identifier';

    private const string TOKEN_ATTRIBUTE = 'oauth_exchange_issued_access_token';

    public function recordUserIdentifier(int|string $userIdentifier): void
    {
        app(Request::class)->attributes->set(self::REQUEST_ATTRIBUTE, $userIdentifier);
    }

    public function recordIssuedAccessToken(string $tokenIdentifier, int|string $userIdentifier): void
    {
        $request = app(Request::class);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $userIdentifier);
        $request->attributes->set(self::TOKEN_ATTRIBUTE, $tokenIdentifier);
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
        $tokenIdentifier = $request->attributes->get(self::TOKEN_ATTRIBUTE);
        $request->attributes->remove(self::REQUEST_ATTRIBUTE);
        $request->attributes->remove(self::TOKEN_ATTRIBUTE);

        if (! is_int($userIdentifier) && ! is_string($userIdentifier)) {
            return true;
        }

        $user = User::query()->find($userIdentifier);

        $issuedToken = is_string($tokenIdentifier)
            ? Passport::token()->newQuery()->find($tokenIdentifier)
            : null;
        $versionMatches = ! is_string($tokenIdentifier)
            || ($issuedToken !== null
                && $issuedToken->oauth_security_version !== null
                && (int) $issuedToken->oauth_security_version === (int) $user?->oauth_security_version);

        if ($user instanceof User && $user->canLogin() && $versionMatches) {
            return true;
        }

        app(OAuthCredentialRevoker::class)->revokeForUserIdentifier($userIdentifier);

        return false;
    }
}
