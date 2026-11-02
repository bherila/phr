<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Lab;

/**
 * Exact reading of a lab result's value text. Nothing here uses a float or rounds.
 *
 * ORDER_RESULTS documents ORD_VALUE as the stored value with a period decimal,
 * ORD_NUM_VALUE as a float with 9999999 for "not numeric" (so it is never read),
 * and VALUE_NORMALIZED as operator, number, operator, number delimited by
 * character 16. There is no comparator column. `value` keeps ORD_VALUE as written.
 *
 * value_numeric is set only for a plain number with no inequality that the column
 * holds without rounding: a "<0.5" is not the measurement 0.5, and a trend or an
 * export must not treat it as one.
 */
final class EhiLabValue
{
    /** Character 16, VALUE_NORMALIZED's documented delimiter. */
    private const string DELIMITER = "\x10";

    private const string NUMBER = '[+-]?(?:\d+(?:\.\d*)?|\.\d+)';

    private const array COMPARATORS = ['<=', '>=', '<', '>', '=', '≤', '≥'];

    /** phr_lab_results.value_numeric, range_min, range_max: decimal(18, 10). */
    private const int MAX_INTEGER_DIGITS = 8;

    private const int MAX_FRACTION_DIGITS = 10;

    /**
     * @return array{comparator: string|null, number: string|null, numeric: string|null, conflicting: bool, unrecognized: bool}
     *                                                                                                                          number: the digits a comparator applies to, or a plain value's own
     *                                                                                                                          numeric: what value_numeric may hold
     *                                                                                                                          conflicting: ORD_VALUE and VALUE_NORMALIZED disagree, so neither is relied on
     *                                                                                                                          unrecognized: VALUE_NORMALIZED is outside its documented shape
     */
    public static function parse(?string $value, ?string $normalized): array
    {
        [$textComparator, $textNumber] = self::fromText($value);
        [$comparator, $number, $unrecognized] = self::fromNormalized($normalized);

        $conflicting = false;
        if ($comparator !== null && $textComparator !== null && $comparator !== $textComparator) {
            $conflicting = true;
        }
        if ($number !== null && $textNumber !== null && self::canonical($number) !== self::canonical($textNumber)) {
            $conflicting = true;
        }
        if ($conflicting) {
            return ['comparator' => null, 'number' => null, 'numeric' => null, 'conflicting' => true, 'unrecognized' => $unrecognized];
        }

        $comparator ??= $textComparator;
        $number = $textNumber ?? $number;

        return [
            'comparator' => $comparator,
            'number' => $number,
            // Only ORD_VALUE itself can be the measurement: a value of "Positive"
            // with a normalized number is not one.
            'numeric' => $comparator === null && $textNumber !== null && self::fitsColumn($textNumber) ? $textNumber : null,
            'conflicting' => false,
            'unrecognized' => $unrecognized,
        ];
    }

    /** A bound the decimal range columns hold exactly, as written; anything else stays text. */
    public static function exactBound(?string $bound): ?string
    {
        if ($bound === null) {
            return null;
        }
        $bound = trim($bound);

        return preg_match('/\A'.self::NUMBER.'\z/', $bound) === 1 && self::fitsColumn($bound) ? $bound : null;
    }

    /**
     * One spelling for numerically equal decimals, or the case-folded text when it
     * is not a number. For comparing values only, never for storing them.
     */
    public static function canonical(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A([+-]?)(\d*)(?:\.(\d*))?\z/', $value, $parts) !== 1 || ($parts[2] === '' && ($parts[3] ?? '') === '')) {
            return mb_strtolower($value);
        }
        $integer = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');
        $digits = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return $parts[1] === '-' && $digits !== '0' ? '-'.$digits : $digits;
    }

    /** @return array{0: string|null, 1: string|null} comparator and number written in the text */
    private static function fromText(?string $value): array
    {
        $value = trim((string) $value);
        if (preg_match('/\A('.implode('|', self::COMPARATORS).')\s*('.self::NUMBER.')\z/u', $value, $parts) === 1) {
            return [$parts[1], $parts[2]];
        }

        return [null, preg_match('/\A'.self::NUMBER.'\z/', $value) === 1 ? $value : null];
    }

    /** @return array{0: string|null, 1: string|null, 2: bool} comparator, number, and whether the shape is unrecognized */
    private static function fromNormalized(?string $normalized): array
    {
        if ($normalized === null || trim($normalized) === '') {
            return [null, null, false];
        }
        $tokens = array_map('trim', explode(self::DELIMITER, $normalized));
        $operator = $tokens[0];
        $number = $tokens[1] ?? '';
        if (($operator !== '' && ! in_array($operator, self::COMPARATORS, true))
            || preg_match('/\A'.self::NUMBER.'\z/', $number) !== 1
            || count($tokens) > 4) {
            return [null, null, true];
        }
        // A second pair is a stated interval: no single comparator describes it.
        if (trim(($tokens[2] ?? '').($tokens[3] ?? '')) !== '') {
            return [null, null, false];
        }

        return [$operator === '' ? null : $operator, $number, false];
    }

    private static function fitsColumn(string $number): bool
    {
        $unsigned = ltrim($number, '+-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        return strlen(ltrim($integer, '0')) <= self::MAX_INTEGER_DIGITS && strlen($fraction) <= self::MAX_FRACTION_DIGITS;
    }
}
