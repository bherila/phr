<?php

namespace App\Support\PHR;

/**
 * A lab reference range written out from its bounds and their unit, for exports
 * whose reader has only text to go on: "13.6 - 17.5 g/dL", or one side alone as
 * "<200 mg/dL" or ">40 mg/dL", the way the browser shows it. A side known to be
 * inclusive reads "<=5 mg/dL" or ">=60 mL/min"; one whose inclusiveness is
 * unknown keeps the strict comparator, as before it was recorded. The unit is
 * the range's own, which may differ from the result's.
 */
final class PhrLabReferenceRangeNotation
{
    /** The range, or '' when it has no numeric bound. */
    public static function fromBounds(object $lab): string
    {
        $low = self::number($lab->range_min ?? null);
        $high = self::number($lab->range_max ?? null);
        $range = match (true) {
            $low !== null && $high !== null => "{$low} - {$high}",
            $high !== null => (($lab->range_max_inclusive ?? null) === true ? '<=' : '<').$high,
            $low !== null => (($lab->range_min_inclusive ?? null) === true ? '>=' : '>').$low,
            default => '',
        };
        $unit = is_string($lab->range_unit ?? null) ? trim($lab->range_unit) : '';

        return $range === '' || $unit === '' ? $range : "{$range} {$unit}";
    }

    /** A stored decimal without its padding ("200.0000000000" -> "200"). */
    public static function number(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (string) $value;
        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return $number === '' || $number === '-' ? '0' : $number;
    }
}
