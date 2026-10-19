<?php

namespace App\Support\AgentApi;

use App\Models\OAuthClientConsent;
use Illuminate\Support\Facades\DB;

/**
 * Durable consent per (person, client, protected resource).
 *
 * Passport alone cannot "consent once": its hasGrantedScopes() sees only
 * unexpired 15-minute access tokens. The consent row is what a first-party
 * client's silent re-authorization is checked against, and its `version` is
 * the fence stamped on every code, rotation family and access token issued
 * under it. Code exchange, refresh and the post-exchange recheck compare that
 * stamp with the current row, so revoking consent (which bumps the version
 * before sweeping credentials) retires every grant issued under the old one.
 */
final class OAuthClientConsents
{
    public function find(int|string $userIdentifier, string $clientIdentifier, ?string $resource): ?OAuthClientConsent
    {
        if ($resource === null || $resource === '') {
            return null;
        }

        return OAuthClientConsent::query()
            ->where('user_id', $userIdentifier)
            ->where('client_id', $clientIdentifier)
            ->where('resource', $resource)
            ->first();
    }

    /**
     * The unrevoked consent that already covers every requested scope, if any.
     *
     * @param  list<string>  $scopes
     */
    public function covering(int|string $userIdentifier, string $clientIdentifier, ?string $resource, array $scopes): ?OAuthClientConsent
    {
        $consent = $this->find($userIdentifier, $clientIdentifier, $resource);

        return $consent !== null && $consent->covers($scopes) ? $consent : null;
    }

    /** Whether a credential stamped with $version is still backed by an unrevoked consent. */
    public function isCurrent(int|string $userIdentifier, string $clientIdentifier, ?string $resource, mixed $version): bool
    {
        if ($version === null || ! is_numeric($version)) {
            return false;
        }
        $consent = $this->find($userIdentifier, $clientIdentifier, $resource);

        return $consent !== null
            && $consent->revoked_at === null
            && $consent->version === (int) $version;
    }

    /**
     * Record the person's approval on the consent screen. An active consent
     * gains the newly approved scopes; a revoked one is replaced by exactly
     * what was approved now, under the version its revocation already bumped.
     * Returns null when there is no resource to bind the consent to.
     *
     * @param  list<string>  $scopes
     */
    public function grant(int|string $userIdentifier, string $clientIdentifier, ?string $resource, array $scopes): ?OAuthClientConsent
    {
        if ($resource === null || $resource === '') {
            return null;
        }

        $connection = config('passport.connection');
        $key = ['user_id' => $userIdentifier, 'client_id' => $clientIdentifier, 'resource' => $resource];

        return DB::connection(is_string($connection) ? $connection : null)
            ->transaction(function () use ($key, $scopes): OAuthClientConsent {
                OAuthClientConsent::query()->createOrFirst($key, [
                    'scopes' => [],
                    'version' => 1,
                    'granted_at' => now(),
                    'revoked_at' => now(),
                ]);
                $consent = OAuthClientConsent::query()->where($key)->lockForUpdate()->firstOrFail();

                $approved = $consent->revoked_at === null
                    ? array_values(array_unique([...$consent->scopes, ...$scopes]))
                    : array_values(array_unique($scopes));
                sort($approved);
                $consent->forceFill([
                    'scopes' => $approved,
                    'granted_at' => $consent->revoked_at === null ? $consent->granted_at : now(),
                    'revoked_at' => null,
                ])->save();

                return $consent;
            }, 1);
    }
}
