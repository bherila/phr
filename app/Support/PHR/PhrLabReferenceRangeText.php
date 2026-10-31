<?php

namespace App\Support\PHR;

/**
 * Recognises a lab reference range text that a one-off C-CDA load stored twice.
 *
 * That load wrote the whole text content of each observationRange, which
 * concatenates its <text> with a <value xsi:type="ST"> repeating it
 * ("NEGNEG"). A data migration repaired the stored rows with this rule; native
 * backup restore applies it to archives taken before that migration.
 */
final class PhrLabReferenceRangeText
{
    /**
     * The one copy of a string written twice, or null when it is not one.
     *
     * The halves must be byte-for-byte equal, so a case-insensitive collation
     * never decides it. A number ("11", "2020") is a value, not a repetition. A
     * string whose half is itself doubled did not come from the load, and
     * halving it would make the rule halve its own output again.
     */
    public static function undoubled(mixed $text): ?string
    {
        if (! is_string($text) || is_numeric($text)) {
            return null;
        }

        $half = self::half($text);

        return $half === null || self::half($half) !== null ? null : $half;
    }

    private static function half(string $text): ?string
    {
        $length = strlen($text);
        if ($length < 2 || $length % 2 !== 0) {
            return null;
        }

        $half = substr($text, 0, intdiv($length, 2));

        return $half === substr($text, intdiv($length, 2)) && trim($half) !== '' ? $half : null;
    }
}
