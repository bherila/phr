<?php

namespace App\Support\AgentApi;

use Illuminate\Contracts\Session\Session;

/**
 * Persists request state that Passport's serialized authorization request
 * cannot carry. Keeping it in the same browser session as Passport's approval
 * token gives both values the same sliding idle lifetime.
 */
final readonly class OAuthAuthorizationStateStore
{
    public function __construct(private Session $session) {}

    public function rememberResource(string $authToken, string $resource): void
    {
        $this->session->put($this->key($authToken), $resource);
    }

    public function resourceFor(string $authToken): ?string
    {
        // Consent submissions can overlap before Passport consumes its session
        // token. Keep every valid submission bound to the original audience.
        $resource = $this->session->get($this->key($authToken));

        return is_string($resource) ? $resource : null;
    }

    private function key(string $authToken): string
    {
        return 'oauth-resource:'.hash('sha256', $authToken);
    }
}
