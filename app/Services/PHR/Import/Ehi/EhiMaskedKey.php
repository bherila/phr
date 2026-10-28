<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * How a source patient key is shown anywhere outside the database: at most the
 * last four characters, and never more than half of the key, so a short key is
 * not shown whole.
 */
final class EhiMaskedKey
{
    public static function mask(?string $key): string
    {
        if ($key === null || $key === '') {
            return '(none)';
        }
        $length = mb_strlen($key);
        $shown = min(4, intdiv($length, 2));

        $tail = $shown > 0 ? (string) preg_replace('/[^\x20-\x7E]/', '?', mb_substr($key, -$shown)) : '';

        return str_repeat('*', max(4, $length - $shown)).$tail;
    }
}
