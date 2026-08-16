<?php

namespace App\Support\AgentApi;

use App\Models\OAuthTokenFamily;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;

final class OAuthExchangeAccountGuard
{
    private const string REQUEST_ATTRIBUTE = 'oauth_exchange_user_identifier';

    private const string TOKEN_ATTRIBUTE = 'oauth_exchange_issued_access_token';

    private const string GRANT_ATTRIBUTE = 'oauth_exchange_validated_grant';

    public function recordValidatedGrant(
        int|string $userIdentifier,
        int $securityVersion,
        ?string $familyIdentifier,
        ?string $resourceUri = null,
    ): void {
        $request = app(Request::class);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $userIdentifier);
        $request->attributes->set(self::GRANT_ATTRIBUTE, [
            'user_identifier' => (string) $userIdentifier,
            'security_version' => $securityVersion,
            'family_identifier' => $familyIdentifier,
            'resource_uri' => $resourceUri,
        ]);
    }

    /** @return array{security_version: int, family_identifier: string|null, resource_uri: string|null}|null */
    public function validatedGrantFor(int|string $userIdentifier): ?array
    {
        $grant = app(Request::class)->attributes->get(self::GRANT_ATTRIBUTE);
        if (! is_array($grant)
            || ($grant['user_identifier'] ?? null) !== (string) $userIdentifier
            || ! is_int($grant['security_version'] ?? null)
            || (! is_string($grant['family_identifier'] ?? null) && ($grant['family_identifier'] ?? null) !== null)
            || (! is_string($grant['resource_uri'] ?? null) && ($grant['resource_uri'] ?? null) !== null)) {
            return null;
        }

        return [
            'security_version' => $grant['security_version'],
            'family_identifier' => $grant['family_identifier'],
            'resource_uri' => $grant['resource_uri'],
        ];
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
        $request->attributes->remove(self::GRANT_ATTRIBUTE);

        if (! is_int($userIdentifier) && ! is_string($userIdentifier)) {
            return true;
        }

        $user = User::query()->find($userIdentifier);

        $issuedToken = is_string($tokenIdentifier)
            ? Passport::token()->newQuery()->find($tokenIdentifier)
            : null;
        $familyIdentifier = $issuedToken === null
            ? null
            : (is_string($issuedToken->oauth_family_id) ? $issuedToken->oauth_family_id : $issuedToken->id);
        $familyIsActive = ! is_string($familyIdentifier)
            || ! OAuthTokenFamily::query()->whereKey($familyIdentifier)->where('revoked', true)->exists();
        $versionMatches = ! is_string($tokenIdentifier)
            || ($issuedToken !== null
                && $issuedToken->oauth_security_version !== null
                && (int) $issuedToken->oauth_security_version === (int) $user?->oauth_security_version);

        if ($user instanceof User && $user->canLogin() && $versionMatches && $familyIsActive) {
            return true;
        }

        $revoker = app(OAuthCredentialRevoker::class);
        if ($user instanceof User && $user->canLogin() && $issuedToken !== null) {
            $revoker->revokeFamilyForAccessToken($issuedToken);
        } else {
            $revoker->revokeForUserIdentifier($userIdentifier);
        }

        return false;
    }
}
