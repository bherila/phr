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

    /** Credential suffixes and chain names with a fixed written form, used for pharmacy and prescriber names. */
    private const array NAME_WORDS = [
        'MD' => 'MD', 'DO' => 'DO', 'PA' => 'PA', 'PA-C' => 'PA-C', 'NP' => 'NP', 'NP-C' => 'NP-C', 'FNP' => 'FNP',
        'FNP-C' => 'FNP-C', 'ANP' => 'ANP', 'AGNP' => 'AGNP', 'APRN' => 'APRN', 'CRNP' => 'CRNP', 'ARNP' => 'ARNP',
        'DNP' => 'DNP', 'CNM' => 'CNM', 'CNP' => 'CNP', 'RN' => 'RN', 'LPN' => 'LPN', 'OD' => 'OD', 'DPM' => 'DPM',
        'DDS' => 'DDS', 'DMD' => 'DMD', 'DC' => 'DC', 'RPH' => 'RPh', 'PHARMD' => 'PharmD', 'DPH' => 'DPh',
        'PHD' => 'PhD', 'MPH' => 'MPH', 'MBA' => 'MBA', 'MS' => 'MS', 'MSN' => 'MSN', 'BSN' => 'BSN', 'FACP' => 'FACP',
        'FACS' => 'FACS', 'FAAP' => 'FAAP', 'JR' => 'Jr.', 'SR' => 'Sr.', 'II' => 'II', 'III' => 'III', 'IV' => 'IV',
        'HEB' => 'HEB', 'CVS' => 'CVS', 'VA' => 'VA', 'KP' => 'KP', 'UCSF' => 'UCSF', 'UCLA' => 'UCLA', 'UPMC' => 'UPMC',
        'HCA' => 'HCA', 'ER' => 'ER', 'ED' => 'ED', 'ICU' => 'ICU', 'WIC' => 'WIC',
    ];

    private const array US_STATES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA',
        'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR',
        'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'PR',
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

        return self::titleCase($value, static fn (string $word, bool $first): ?string => self::labelerWord($word, $first, false));
    }

    /**
     * Pharmacy, prescriber and place names as captured from a label or receipt. All-caps text gets
     * proper-name casing (labeler rules plus credentials such as MD, PA-C, RPh and Mc names; with
     * `$place`, US state codes); text that is not all caps is returned as written.
     */
    public static function properName(?string $value, bool $place = false): ?string
    {
        $value = self::clean($value);
        if ($value === null || ! self::isAllCaps($value, false)) {
            return $value;
        }

        return self::titleCase($value, static fn (string $word, bool $first): ?string => self::labelerWord($word, $first, true, $place));
    }

    private static function labelerWord(string $word, bool $first, bool $names, bool $place = false): ?string
    {
        if ($names) {
            if ($place && in_array($word, self::US_STATES, true)) {
                return $word;
            }
            if (isset(self::NAME_WORDS[$word])) {
                return self::NAME_WORDS[$word];
            }
            // Mc names: MCDONALD is McDonald.
            if (preg_match('/\AMC([A-Z]{3,})\z/', $word, $mc) === 1) {
                return 'Mc'.ucfirst(strtolower($mc[1]));
            }
        }
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
    }

    /**
     * Strengths and package descriptions: lowercase the words, write units in
     * their standard form (mg, mcg, mL, g, IU, %), and leave numbers and NDC
     * codes alone. Whatever the casing, UCUM bracket units become plain
     * units, mEq/mL get their standard casing, and a number is spaced from its
     * unit (5 mL; the /1 denominator stays). Standard units and connectors ("in") do not count as
     * lowercase evidence, because openFDA writes them that way inside
     * otherwise uppercase text ("30 TABLET in 1 BOTTLE").
     */
    public static function measureText(?string $value): ?string
    {
        $value = self::clean($value);
        if ($value === null) {
            return null;
        }

        // UCUM bracket units ("[iU]") are masked while deciding the casing, so their
        // letters are neither counted as evidence nor lowercased.
        if (self::isAllCaps((string) preg_replace('/\[[^\]]*\]/', '', $value), true)) {
            $value = (string) preg_replace_callback('/\[[^\]]*\]|[A-Za-z]+/', static function (array $match): string {
                $word = $match[0];
                if ($word[0] === '[') {
                    return $word;
                }

                return self::UNITS[strtoupper($word)] ?? strtolower($word);
            }, $value);
        }

        return self::fixUnits($value);
    }

    /**
     * Unit notation, whatever the casing of the surrounding text: UCUM brackets
     * become plain units, mEq and mL get their standard casing, and a number is
     * separated from its unit. Text inside parentheses (NDC codes) is never touched.
     */
    private static function fixUnits(string $value): string
    {
        $value = (string) preg_replace_callback('/\[([^\]]*)\]/', static function (array $match): string {
            $inner = $match[1];
            if (in_array($inner, ['iU', 'IU'], true)) {
                return 'IU';
            }
            if ($inner === "USP'U") {
                return 'USP units';
            }
            if (preg_match('/\Ahp_([A-Z])\z/', $inner, $potency) === 1) {
                return $potency[1];
            }

            return $match[0];
        }, $value);

        $parts = preg_split('/(\([^)]*\))/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                continue;
            }
            $part = (string) preg_replace('/(?<![A-Za-z])(?<=[\d\s\/]|^)(?:meq)(?![A-Za-z])/i', 'mEq', $part);
            $part = (string) preg_replace('/(?<![A-Za-z])(?<=[\d\s\/]|^)(?:ml)(?![A-Za-z])/i', 'mL', $part);
            $part = (string) preg_replace(
                '/(?<![\w.\-])(\d+(?:\.\d+)?)(mg|mcg|kg|g|mL|L|IU|mEq|mmol|%|units?)(?![A-Za-z])/',
                '$1 $2',
                $part,
            );
            $parts[$index] = $part;
        }

        return implode('', $parts);
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
        foreach ($parts as $position => $part) {
            if ($part === '-' || $part === '/') {
                $cased[] = $part;

                continue;
            }
            if (count($parts) > 1 && $part !== '' && ($fixedPart = $special($part, $first && $position === 0)) !== null) {
                $cased[] = $fixedPart;

                continue;
            }
            $lower = mb_strtolower($part);
            $apostrophe = mb_strpos($lower, "'");
            if ($apostrophe !== false && mb_strlen($lower) - $apostrophe === 2) {
                $cased[] = mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);

                continue;
            }
            $cased[] = implode("'", array_map(
                static fn (string $piece): string => mb_strtoupper(mb_substr($piece, 0, 1)).mb_substr($piece, 1),
                explode("'", $lower),
            ));
        }

        return $lead.implode('', $cased).$trail;
    }
}
