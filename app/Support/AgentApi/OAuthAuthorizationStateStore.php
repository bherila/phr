<?php

namespace App\Support\AgentApi;

use Illuminate\Contracts\Cache\Repository;

/**
 * Persists request state that Passport's serialized authorization request
 * cannot carry. Entries use the browser session lifetime because the consent
 * form remains valid for that same period.
 */
final readonly class OAuthAuthorizationStateStore
{
    public function __construct(private Repository $cache) {}

    public function rememberResource(string $authToken, string $resource): void
    {
        $this->cache->put(
            $this->key($authToken),
            $resource,
            now()->addMinutes(max(1, (int) config('session.lifetime', 120))),
        );
    }

    public function resourceFor(string $authToken): ?string
    {
        // Consent submissions can overlap before Passport consumes its session
        // token. Keep every valid submission bound to the original audience.
        $resource = $this->cache->get($this->key($authToken));

        return is_string($resource) ? $resource : null;
    }

    private function key(string $authToken): string
    {
        return 'oauth-resource:'.hash('sha256', $authToken);
    }
}
