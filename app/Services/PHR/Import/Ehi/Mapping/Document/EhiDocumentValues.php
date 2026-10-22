<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Models\PhrEhiRevision;

/**
 * Exact, float-free reading of raw EHI cell strings for the document mapping.
 *
 * Keys stay strings. Ordering compares integers and decimals digit by digit, so
 * "64000.10" never rounds and a fractional contact key is never read as a date.
 * Date/time text is parsed only in recognized shapes and never shifted through a
 * server timezone; the caller records whether the column is documented UTC.
 */
final class EhiDocumentValues
{
    /** A raw cell, with absent, explicit-null, and empty all reading as null. */
    public static function cell(PhrEhiRevision $revision, string $column): ?string
    {
        $value = $revision->values_json[$column] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A copy of a source value for proposal metadata, cut UTF-8-safely to at most $bytes bytes. */
    public static function bounded(?string $value, int $bytes): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_scrub($value, 'UTF-8');

        return strlen($value) <= $bytes ? $value : mb_strcut($value, 0, $bytes, 'UTF-8');
    }

    /**
     * A file name within $maxChars characters, keeping its extension when the
     * name has to be cut (the document column holds 255 characters).
     */
    public static function boundedFileName(string $name, int $maxChars = 255): string
    {
        $name = mb_scrub($name, 'UTF-8');
        if (mb_strlen($name, 'UTF-8') <= $maxChars) {
            return $name;
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' && mb_strlen($extension, 'UTF-8') <= 16 ? '.'.$extension : '';

        return mb_substr($name, 0, $maxChars - mb_strlen($suffix, 'UTF-8'), 'UTF-8').$suffix;
    }

    /** A canonical non-negative integer string ("0", "17"; never "007" or "1.0"). */
    public static function isExactInteger(string $value): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1;
    }

    /** Compare non-negative integer strings exactly; leading zeros tie-break by raw bytes. */
    public static function compareIntegers(string $a, string $b): int
    {
        $digitsA = preg_match('/\A[0-9]+\z/', $a) === 1;
        $digitsB = preg_match('/\A[0-9]+\z/', $b) === 1;
        if (! $digitsA || ! $digitsB) {
            // Non-numeric keys sort after numeric ones, then by raw bytes.
            return $digitsA !== $digitsB ? ($digitsA ? -1 : 1) : strcmp($a, $b);
        }

        return self::compareDigits(ltrim($a, '0'), ltrim($b, '0')) ?: strcmp($a, $b);
    }

    /**
     * Compare non-negative decimal strings exactly (e.g. Epic *_DATE_REAL contact
     * keys). Malformed values sort after well-formed ones, then by raw bytes.
     * Numerically equal values compare equal.
     */
    public static function compareDecimals(string $a, string $b): int
    {
        $parsedA = self::decimalParts($a);
        $parsedB = self::decimalParts($b);
        if ($parsedA === null || $parsedB === null) {
            return ($parsedA === null) !== ($parsedB === null) ? ($parsedA === null ? 1 : -1) : strcmp($a, $b);
        }

        // Equal values ("65000.1" and "65000.10") compare equal; callers break ties.
        return self::compareDigits($parsedA[0], $parsedB[0])
            ?: (strcmp($parsedA[1], $parsedB[1]) <=> 0);
    }

    /**
     * Parse source date/time text in a recognized shape. Returns the calendar date,
     * the time of day if one was supplied, and its precision. Nothing is shifted.
     *
     * Recognized: YYYY-MM-DD[( |T)HH:MM[:SS[.fff]]], and M/D/YYYY[ h:mm[:ss][ AM|PM]].
     *
     * @return array{date: string, time: string|null, precision: string}|null
     */
    public static function dateTime(string $raw): ?array
    {
        $text = trim($raw);
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,9})?)?)?\z/', $text, $m) === 1) {
            return self::assemble((int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? null, $m[5] ?? null, $m[6] ?? null, null);
        }
        if (preg_match('/\A(\d{1,2})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\s*([AaPp][Mm]))?)?\z/', $text, $m) === 1) {
            return self::assemble((int) $m[3], (int) $m[1], (int) $m[2], $m[4] ?? null, $m[5] ?? null, $m[6] ?? null, $m[7] ?? null);
        }

        return null;
    }

    /** @return array{date: string, time: string|null, precision: string}|null */
    private static function assemble(int $year, int $month, int $day, ?string $hour, ?string $minute, ?string $second, ?string $meridiem): ?array
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($hour === null || $hour === '') {
            return ['date' => $date, 'time' => null, 'precision' => 'date'];
        }

        $h = (int) $hour;
        if ($meridiem !== null && $meridiem !== '') {
            if ($h < 1 || $h > 12) {
                return null;
            }
            $pm = strtolower($meridiem) === 'pm';
            $h = $h % 12 + ($pm ? 12 : 0);
        }
        $i = (int) $minute;
        $s = $second === null || $second === '' ? 0 : (int) $second;
        if ($h > 23 || $i > 59 || $s > 59) {
            return null;
        }

        return [
            'date' => $date,
            'time' => sprintf('%02d:%02d:%02d', $h, $i, $s),
            'precision' => $second === null || $second === '' ? 'minute' : 'second',
        ];
    }

    /** @return array{0: string, 1: string}|null integer digits without leading zeros, fraction without trailing zeros */
    private static function decimalParts(string $value): ?array
    {
        if (preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/', $value, $m) !== 1) {
            return null;
        }

        return [ltrim($m[1], '0'), rtrim($m[2] ?? '', '0')];
    }

    private static function compareDigits(string $a, string $b): int
    {
        return (strlen($a) <=> strlen($b)) ?: (strcmp($a, $b) <=> 0);
    }
}
