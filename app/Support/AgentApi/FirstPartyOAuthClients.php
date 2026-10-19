<?php

namespace App\Support\AgentApi;

use Laravel\Passport\Client;

/**
 * The application-registered clients PHR treats as first-party: listed by id
 * in AGENT_API_FIRST_PARTY_OAUTH_CLIENTS, confidential, authorization-code
 * only, never dynamically registered, and not revoked. Such a client still
 * shows the consent screen once; it may only skip it afterwards.
 */
final class FirstPartyOAuthClients
{
    /** @return list<string> */
    public function ids(): array
    {
        $configured = config('agent_api.first_party_oauth_clients', []);

        return is_array($configured)
            ? array_values(array_filter($configured, static fn (mixed $id): bool => is_string($id) && $id !== ''))
            : [];
    }

    public function allows(Client $client): bool
    {
        $registeredAtColumn = config('bherila-auth.oauth_server.dynamic_clients.registered_at_column', 'dynamically_registered_at');
        $grantTypes = $client->getAttribute('grant_types');

        return in_array((string) $client->getKey(), $this->ids(), true)
            && ! (bool) $client->getAttribute('revoked')
            && $client->confidential()
            && is_array($grantTypes)
            && array_diff($grantTypes, ['authorization_code', 'refresh_token']) === []
            && (! is_string($registeredAtColumn) || $client->getAttribute($registeredAtColumn) === null);
    }
}
