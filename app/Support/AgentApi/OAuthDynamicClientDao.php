<?php

namespace App\Support\AgentApi;

use DateTimeInterface;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * Typed persistence boundary for dynamically registered OAuth clients.
 *
 * Both authorization and pruning acquire the same client-row lock through
 * this DAO. Callers must already be inside the Passport database transaction.
 */
final class OAuthDynamicClientDao
{
    public function lockForAuthorization(string $clientId): ?Client
    {
        return Passport::client()->newQuery()
            ->whereKey($clientId)
            ->lockForUpdate()
            ->first();
    }

    public function markAuthorized(Client $client): void
    {
        if ($client->dynamically_registered_at === null || $client->first_authorized_at !== null) {
            return;
        }

        $client->forceFill(['first_authorized_at' => now()])->save();
    }

    /** @return list<string> */
    public function staleUnusedIds(DateTimeInterface $registeredBefore): array
    {
        return Passport::client()->newQuery()
            ->whereNotNull('dynamically_registered_at')
            ->where('dynamically_registered_at', '<=', $registeredBefore)
            ->whereNull('first_authorized_at')
            ->whereDoesntHave('authCodes')
            ->whereDoesntHave('tokens')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    public function lockUnusedForPruning(string $clientId, DateTimeInterface $registeredBefore): ?Client
    {
        return Passport::client()->newQuery()
            ->whereKey($clientId)
            ->whereNotNull('dynamically_registered_at')
            ->where('dynamically_registered_at', '<=', $registeredBefore)
            ->whereNull('first_authorized_at')
            ->whereDoesntHave('authCodes')
            ->whereDoesntHave('tokens')
            ->lockForUpdate()
            ->first();
    }
}
