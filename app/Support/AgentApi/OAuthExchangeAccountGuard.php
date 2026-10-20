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

    public const string PATIENT_RESPONSE_ATTRIBUTE = 'oauth_selected_patient_response';

    private const string GRANT_ATTRIBUTE = 'oauth_exchange_validated_grant';

    public function recordValidatedGrant(
        int|string $userIdentifier,
        int $securityVersion,
        ?string $familyIdentifier,
        ?string $resourceUri = null,
        ?int $consentVersion = null,
        ?string $patientPublicId = null,
        ?int $patientConsentVersion = null,
    ): void {
        $request = app(Request::class);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $userIdentifier);
        $request->attributes->set(self::GRANT_ATTRIBUTE, [
            'user_identifier' => (string) $userIdentifier,
            'security_version' => $securityVersion,
            'family_identifier' => $familyIdentifier,
            'resource_uri' => $resourceUri,
            'consent_version' => $consentVersion,
            'patient_public_id' => $patientPublicId,
            'patient_consent_version' => $patientConsentVersion,
        ]);
    }

    /**
     * Add the consent version an authorization was approved under to the grant
     * the authorization boundary already validated for the same person. The
     * authorization code persisted for this request is stamped with it.
     */
    public function recordApprovedConsent(int|string $userIdentifier, int $consentVersion): void
    {
        $request = app(Request::class);
        $grant = $request->attributes->get(self::GRANT_ATTRIBUTE);
        if (! is_array($grant) || ($grant['user_identifier'] ?? null) !== (string) $userIdentifier) {
            return;
        }
        $grant['consent_version'] = $consentVersion;
        $request->attributes->set(self::GRANT_ATTRIBUTE, $grant);
    }

    public function recordSelectedPatient(int|string $userIdentifier, string $publicId, int $version): void
    {
        $request = app(Request::class);
        $grant = $request->attributes->get(self::GRANT_ATTRIBUTE);
        if (is_array($grant) && ($grant['user_identifier'] ?? null) === (string) $userIdentifier) {
            $grant['patient_public_id'] = $publicId;
            $grant['patient_consent_version'] = $version;
            $request->attributes->set(self::GRANT_ATTRIBUTE, $grant);
        }
    }

    /** @return array{security_version: int, family_identifier: string|null, resource_uri: string|null, consent_version: int|null, patient_public_id: string|null, patient_consent_version: int|null}|null */
    public function validatedGrantFor(int|string $userIdentifier): ?array
    {
        $grant = app(Request::class)->attributes->get(self::GRANT_ATTRIBUTE);
        if (! is_array($grant)
            || ($grant['user_identifier'] ?? null) !== (string) $userIdentifier
            || ! is_int($grant['security_version'] ?? null)
            || (! is_string($grant['family_identifier'] ?? null) && ($grant['family_identifier'] ?? null) !== null)
            || (! is_string($grant['resource_uri'] ?? null) && ($grant['resource_uri'] ?? null) !== null)
            || (! is_int($grant['consent_version'] ?? null) && ($grant['consent_version'] ?? null) !== null)) {
            return null;
        }

        return [
            'security_version' => $grant['security_version'],
            'family_identifier' => $grant['family_identifier'],
            'resource_uri' => $grant['resource_uri'],
            'consent_version' => $grant['consent_version'] ?? null,
            'patient_public_id' => $grant['patient_public_id'] ?? null,
            'patient_consent_version' => $grant['patient_consent_version'] ?? null,
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

        // The consent the credential was issued under, re-read after the exchange
        // committed: a revocation that bumped the version while this exchange
        // was in flight may have swept the client's families before this one
        // existed, so an escaped family is retired here instead.
        $consentIsCurrent = $issuedToken === null
            || $issuedToken->user_id === null
            || app(OAuthClientConsents::class)->isCurrent(
                $issuedToken->user_id,
                (string) $issuedToken->client_id,
                is_string($issuedToken->resource_uri) ? $issuedToken->resource_uri : null,
                $issuedToken->oauth_consent_version,
            );

        if ($user instanceof User && $user->mayHoldOAuthCredentials() && $versionMatches && $familyIsActive && $consentIsCurrent) {
            $patient = is_string($familyIdentifier) ? OAuthTokenFamily::query()->whereKey($familyIdentifier)->value('patient_public_id') : null;
            // A revoked selection loses its context, not the client's other patient grants.
            if (is_string($patient) && $issuedToken !== null && app(OAuthPatientConsents::class)->current(
                $issuedToken->user_id, (string) $issuedToken->client_id,
                is_string($issuedToken->resource_uri) ? $issuedToken->resource_uri : null, $patient,
            )) {
                $request->attributes->set(self::PATIENT_RESPONSE_ATTRIBUTE, $patient);
            }

            return true;
        }

        $revoker = app(OAuthCredentialRevoker::class);
        if ($user instanceof User && $user->mayHoldOAuthCredentials() && $issuedToken !== null) {
            $revoker->revokeFamilyForAccessToken($issuedToken);
        } else {
            $revoker->revokeForUserIdentifier($userIdentifier);
        }

        return false;
    }
}
