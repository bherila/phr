<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

/**
 * Canonical JSON for EHI digests: object keys sorted (byte order) at every depth,
 * list order kept, slashes and Unicode unescaped. Two equal structures always
 * encode, and therefore hash, identically.
 */
final class EhiCanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (array_is_list($value)) {
            return $value;
        }
        ksort($value, SORT_STRING);

        return $value;
    }
}
