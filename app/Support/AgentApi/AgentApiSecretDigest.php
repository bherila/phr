<?php

namespace App\Support\AgentApi;

use LogicException;

/** Domain-separated keyed digests for low-entropy agent metadata. */
final class AgentApiSecretDigest
{
    /** @return non-empty-list<string> */
    public static function candidates(string $domain, string $value): array
    {
        $key = (string) config('agent_api.mutation_digest_key');
        if ($key === '') {
            throw new LogicException('AGENT_API_MUTATION_DIGEST_KEY is required to digest mutation identities.');
        }
        if (hash_equals((string) config('app.key'), $key)) {
            throw new LogicException('AGENT_API_MUTATION_DIGEST_KEY must be independent from APP_KEY.');
        }

        return [self::withKey($domain, $value, $key)];
    }

    private static function withKey(string $domain, string $value, string $key): string
    {
        return hash_hmac('sha256', $domain."\0".$value, $key);
    }
}
