<?php

namespace App\Support\PHR;

/**
 * Recognises a lab reference range text that a one-off C-CDA load stored twice.
 *
 * That load wrote the whole text content of each observationRange, which
 * concatenates its <text> with a <value xsi:type="ST"> repeating it
 * ("NEGNEG"), and an IVL_PQ in a non-UCUM unit repeats the unit in each
 * bound's translation/originalText ("4.3 - 5.6 PERCENTPERCENTPERCENT"). Data
 * migrations repaired the stored rows with these rules; native backup restore
 * applies them to archives taken before those migrations.
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

    /**
     * The text with a repeated trailing range unit stored once
     * ("<165 IU/mLIU/mL" -> "<165 IU/mL"), or null when it has none.
     *
     * Only the record's own range_unit counts, compared byte for byte, and
     * never a numeric unit: with UCUM's unitless "1", "<11" is a range.
     */
    public static function withoutRepeatedUnit(mixed $text, mixed $unit): ?string
    {
        if (! is_string($text) || ! is_string($unit) || trim($unit) === '' || is_numeric($unit)) {
            return null;
        }

        $repaired = $text;
        while (str_ends_with($repaired, $unit.$unit)) {
            $repaired = substr($repaired, 0, -strlen($unit));
        }

        return $repaired === $text ? null : $repaired;
    }

    /** Both repairs, in the order the migrations applied them. */
    public static function repaired(mixed $text, mixed $unit): mixed
    {
        $text = self::undoubled($text) ?? $text;

        return self::withoutRepeatedUnit($text, $unit) ?? $text;
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
