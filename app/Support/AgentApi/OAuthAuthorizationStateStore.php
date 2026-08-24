<?php

namespace App\Support\AgentApi;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Session\Session;

/**
 * Persists request state that Passport's serialized authorization request
 * cannot carry. The session copy binds it to Passport's approval token; the
 * hashed, short-lived cache copy preserves that binding if session middleware
 * drops only the custom value between the approval page and its submission.
 */
final readonly class OAuthAuthorizationStateStore
{
    public function __construct(
        private Session $session,
        private CacheRepository $cache,
    ) {}

    public function currentApprovalToken(): ?string
    {
        $authToken = $this->session->get('authToken');

        return is_string($authToken) ? $authToken : null;
    }

    public function rememberResource(string $authToken, string $resource): void
    {
        $this->session->put($this->key($authToken), $resource);
        $this->cache->put($this->key($authToken), $resource, $this->ttlSeconds());
    }

    public function resourceFor(string $authToken): ?string
    {
        // Consent submissions can overlap before Passport consumes its session
        // token. Keep every valid submission bound to the original audience.
        $resource = $this->session->get($this->key($authToken));
        if (! is_string($resource)) {
            $resource = $this->cache->get($this->key($authToken));
        }

        return is_string($resource) ? $resource : null;
    }

    public function forgetResource(string $authToken): void
    {
        $this->session->forget($this->key($authToken));
        $this->cache->forget($this->key($authToken));
    }

    private function key(string $authToken): string
    {
        return 'oauth-resource:'.hash('sha256', $authToken);
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('session.lifetime', 120) * 60);
    }
}
