<?php

namespace App\Services\PHR\Ndc;

/**
 * Converts the ALL-CAPS values openFDA returns into appropriate casing.
 *
 * Every method acts only on a value that is all uppercase; a value that is
 * already mixed-case or lowercase is returned as given. The raw response is
 * kept separately in the fill's snapshot, so nothing here loses information.
 */
final class NdcCasing
{
    /** Standard written forms of units, by uppercase spelling. */
    private const array UNITS = [
        'MG' => 'mg', 'MCG' => 'mcg', 'UG' => 'mcg', 'ML' => 'mL', 'G' => 'g', 'IU' => 'IU',
        'KG' => 'kg', 'L' => 'L', 'MEQ' => 'mEq', 'MMOL' => 'mmol', 'UNT' => 'unit',
    ];

    /** Lowercase connectors that FDA package descriptions write in lowercase already. */
    private const array NEUTRAL_WORDS = ['in', 'of', 'and', 'with', 'per'];

    /** Dosage-form and product suffixes that stay uppercase in a brand name. */
    private const array BRAND_ACRONYMS = [
        'XR', 'XL', 'ER', 'SR', 'CR', 'DR', 'LA', 'SA', 'CD', 'HCT', 'HCTZ', 'ODT', 'DS', 'PM', 'AM', 'IV',
        'USP', 'HFA', 'DPI', 'SL', 'IR', 'EC', 'DT', 'OTC', 'II', 'III', 'IV',
    ];

    /** Corporate suffixes and acronyms with a fixed written form. */
    private const array LABELER_WORDS = [
        'INC' => 'Inc.', 'CORP' => 'Corp.', 'CO' => 'Co.', 'LTD' => 'Ltd.', 'LLC' => 'LLC', 'LLP' => 'LLP',
        'LP' => 'LP', 'PLC' => 'PLC', 'USA' => 'USA', 'US' => 'US', 'NV' => 'NV', 'AG' => 'AG', 'SA' => 'SA',
        'SE' => 'SE', 'GMBH' => 'GmbH', 'RX' => 'Rx', 'CVS' => 'CVS', 'ST' => 'St.', 'MT' => 'Mt.', 'DR' => 'Dr.',
    ];

    private const array LABELER_LOWERCASE = ['of', 'and', 'the', 'for', 'de', 'la', 'et'];

    /** Generic and substance names, dosage forms and routes: lowercase. */
    public static function lowercase(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === null) {
            return null;
        }

        return self::isAllCaps($value, false) ? mb_strtolower($value) : $value;
    }

    /** Brand names: title case, keeping dosage-form acronyms such as XR. */
    public static function brand(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === null || ! self::isAllCaps($value, false)) {
            return $value;
        }

        return self::titleCase($value, static function (string $word, bool $first): ?string {
            return in_array($word, self::BRAND_ACRONYMS, true) && ! $first ? $word : null;
        });
    }

    /** Labeler names: title case, keeping corporate suffixes and acronyms (Inc., LLC, USA, L.P.). */
    public static function labeler(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === null || ! self::isAllCaps($value, false)) {
            return $value;
        }

        return self::titleCase($value, static function (string $word, bool $first): ?string {
            if (isset(self::LABELER_WORDS[$word])) {
                return self::LABELER_WORDS[$word];
            }
            // Dotted initialisms (L.P., U.S.A.) keep their capitals.
            if (preg_match('/\A([A-Z]\.){2,}\z/', $word) === 1) {
                return $word;
            }
            if (! $first && in_array(strtolower($word), self::LABELER_LOWERCASE, true)) {
                return strtolower($word);
            }
            // A short run of consonants is an initialism (KVK, HEB), not a word.
            if (strlen($word) <= 4 && preg_match('/\A[B-DF-HJ-NP-TV-Z]+\z/', $word) === 1) {
                return $word;
            }

            return null;
        });
    }

    /**
     * Strengths and package descriptions: lowercase the words, write units in
     * their standard form (mg, mcg, mL, g, IU, %), and leave numbers and NDC
     * codes alone. Standard units and connectors ("in") do not count as
     * lowercase evidence, because openFDA writes them that way inside
     * otherwise uppercase text ("30 TABLET in 1 BOTTLE").
     */
    public static function measureText(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === null || ! self::isAllCaps($value, true)) {
            return $value;
        }

        return (string) preg_replace_callback('/[A-Za-z]+/', static function (array $match): string {
            $word = $match[0];
            $upper = strtoupper($word);

            return self::UNITS[$upper] ?? strtolower($word);
        }, $value);
    }

    /**
     * Whether the letters in `$value` are all uppercase and there are some.
     * With `$neutralUnits`, a standard unit or connector spelled in its
     * standard (lowercase or mixed) form is ignored rather than counted.
     */
    public static function isAllCaps(string $value, bool $neutralUnits): bool
    {
        if (preg_match_all('/[A-Za-z]+/', $value, $matches) === 0) {
            return false;
        }
        $evidence = 0;
        foreach ($matches[0] as $word) {
            if ($neutralUnits && (in_array($word, self::UNITS, true) || in_array($word, self::NEUTRAL_WORDS, true))) {
                continue;
            }
            if ($word !== strtoupper($word)) {
                return false;
            }
            // A lone uppercase letter ("G", "L") is a unit symbol or initial, not shouting.
            if ($neutralUnits && strlen($word) === 1 && isset(self::UNITS[$word])) {
                continue;
            }
            $evidence++;
        }

        return $evidence > 0;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /** @param callable(string, bool): ?string $special */
    private static function titleCase(string $value, callable $special): string
    {
        $words = explode(' ', $value);
        $out = [];
        foreach ($words as $index => $word) {
            $out[] = self::titleWord($word, $index === 0, $special);
        }

        return implode(' ', $out);
    }

    /** @param callable(string, bool): ?string $special */
    private static function titleWord(string $word, bool $first, callable $special): string
    {
        // Peel surrounding punctuation off so "(LLC)," and "INC.," are recognised.
        preg_match('/\A([^A-Za-z0-9]*)(.*?)([^A-Za-z0-9.]*)\z/su', $word, $m);
        [$lead, $core, $trail] = [$m[1] ?? '', $m[2] ?? $word, $m[3] ?? ''];
        $bare = rtrim($core, '.');
        $dots = substr($core, strlen($bare));

        $fixed = $special($core, $first);
        if ($fixed !== null) {
            return $lead.$fixed.$trail;
        }
        $fixed = $special($bare, $first);
        if ($fixed !== null) {
            return $lead.$fixed.(str_ends_with($fixed, '.') ? '' : $dots).$trail;
        }

        // Hyphenated and slashed parts are cased separately (A-S); an apostrophe
        // keeps a possessive lowercase (Mary's) while a name prefix is cased (O'Brien).
        $parts = preg_split('/([-\/])/', $core, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$core];
        $cased = [];
        foreach ($parts as $part) {
            if ($part === '-' || $part === '/') {
                $cased[] = $part;

                continue;
            }
            $lower = mb_strtolower($part);
            $apostrophe = mb_strpos($lower, "'");
            if ($apostrophe !== false && mb_strlen($lower) - $apostrophe === 2) {
                $cased[] = mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);

                continue;
            }
            $cased[] = $apostrophe !== false ? mb_convert_case($lower, MB_CASE_TITLE) : mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
        }

        return $lead.implode('', $cased).$trail;
    }
}
