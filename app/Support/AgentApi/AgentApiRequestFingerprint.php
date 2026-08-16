<?php

namespace App\Support\AgentApi;

final class AgentApiRequestFingerprint
{
    /** @param array<string, mixed> $payload */
    public static function for(array $payload): string
    {
        return AgentApiSecretDigest::for(
            'mutation-request',
            json_encode(
                self::sort($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sort($item);
        }

        return $value;
    }
}
