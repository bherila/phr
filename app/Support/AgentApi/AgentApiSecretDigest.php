<?php

namespace App\Support\AgentApi;

use LogicException;

/** Domain-separated keyed digests for low-entropy agent metadata. */
final class AgentApiSecretDigest
{
    /** @return non-empty-list<string> */
    public static function candidates(string $domain, string $value): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => self::withKey($domain, $value, $key),
            self::keys(),
        )));
    }

    /** @return non-empty-list<string> */
    private static function keys(): array
    {
        $current = (string) config('app.key');
        if ($current === '') {
            throw new LogicException('APP_KEY is required to digest agent API metadata.');
        }
        $previous = config('app.previous_keys', []);
        $keys = [$current];
        if (is_array($previous)) {
            foreach ($previous as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    private static function withKey(string $domain, string $value, string $key): string
    {
        return hash_hmac('sha256', $domain."\0".$value, $key);
    }
}
