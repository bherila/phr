<?php

namespace App\Support\AgentApi;

final class AgentApiRequestFingerprint
{
    /**
     * @param  array<string, mixed>  $payload
     * @return non-empty-list<string>
     */
    public static function candidates(array $payload): array
    {
        return AgentApiSecretDigest::candidates('mutation-request', self::json($payload));
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload): string
    {
        return json_encode(
            self::sort($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function sort(mixed $value): mixed
    {
        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map(self::sort(...), $properties);
        }
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
