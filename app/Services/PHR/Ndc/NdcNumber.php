<?php

namespace App\Services\PHR\Ndc;

/**
 * Pure NDC (National Drug Code) parsing and conversion. No I/O.
 *
 * A labeler-product-package code is written in three hyphenated formats of ten
 * digits (4-4-2, 5-3-2, 5-4-1) and billed as eleven digits (5-4-2) with a zero
 * padded into one segment. Going from eleven digits back to ten is therefore
 * ambiguous: any segment that starts with 0 may be the padded one. This class
 * produces every candidate and leaves choosing the real one to the openFDA
 * packaging directory.
 */
final class NdcNumber
{
    /** The three ten-digit layouts, as segment lengths. */
    private const array TEN_DIGIT_LAYOUTS = [[4, 4, 2], [5, 3, 2], [5, 4, 1]];

    /**
     * Strips spaces, hyphens and an optional leading "NDC" label, and reports
     * what was captured.
     *
     * @return array{valid: bool, digits: string, hyphenated: bool, layout: list<int>|null}
     */
    public static function parse(string $input): array
    {
        $invalid = ['valid' => false, 'digits' => '', 'hyphenated' => false, 'layout' => null];
        $text = trim((string) preg_replace('/^\s*NDC\s*[:#]?\s*/i', '', $input));
        // Printed codes use ASCII hyphens, but OCR and word processors substitute dashes.
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);
        $text = (string) preg_replace('/\s+/', '', $text);
        if ($text === '' || preg_match('/\A[0-9-]+\z/', $text) !== 1) {
            return $invalid;
        }

        if (str_contains($text, '-')) {
            $segments = explode('-', $text);
            if (count($segments) !== 3) {
                return $invalid;
            }
            $layout = array_map(strlen(...), $segments);
            foreach ($segments as $segment) {
                if ($segment === '') {
                    return $invalid;
                }
            }
            if (in_array($layout, [...self::TEN_DIGIT_LAYOUTS, [5, 4, 2]], true)) {
                return ['valid' => true, 'digits' => implode('', $segments), 'hyphenated' => true, 'layout' => $layout];
            }

            return $invalid;
        }

        return in_array(strlen($text), [10, 11], true)
            ? ['valid' => true, 'digits' => $text, 'hyphenated' => false, 'layout' => null]
            : $invalid;
    }

    /**
     * Every hyphenated ten-digit package NDC the input could mean, in a stable
     * order. Empty when the input is not an NDC.
     *
     * - Hyphenated ten-digit input is exact: one candidate.
     * - Ten digits without hyphens could be 4-4-2, 5-3-2 or 5-4-1: three candidates.
     * - Eleven digits (5-4-2, hyphenated or not): drop one leading zero from each
     *   segment that has one, in turn.
     *
     * @return list<string>
     */
    public static function packageCandidates(string $input): array
    {
        $parsed = self::parse($input);
        if (! $parsed['valid']) {
            return [];
        }
        $digits = $parsed['digits'];

        if (strlen($digits) === 10) {
            if ($parsed['hyphenated']) {
                return [self::join($digits, $parsed['layout'] ?? [])];
            }

            return array_map(static fn (array $layout): string => self::join($digits, $layout), self::TEN_DIGIT_LAYOUTS);
        }

        $segments = [substr($digits, 0, 5), substr($digits, 5, 4), substr($digits, 9, 2)];
        $candidates = [];
        foreach ([0, 1, 2] as $index) {
            if (! str_starts_with($segments[$index], '0')) {
                continue;
            }
            $dropped = $segments;
            $dropped[$index] = substr($segments[$index], 1);
            $candidates[] = implode('-', $dropped);
        }

        return array_values(array_unique($candidates));
    }

    /** The eleven-digit billing form (5-4-2, no hyphens) of a hyphenated ten-digit NDC. */
    public static function toNdc11(string $ndc10): string
    {
        $segments = explode('-', $ndc10);
        if (count($segments) !== 3) {
            return $ndc10;
        }

        return str_pad($segments[0], 5, '0', STR_PAD_LEFT)
            .str_pad($segments[1], 4, '0', STR_PAD_LEFT)
            .str_pad($segments[2], 2, '0', STR_PAD_LEFT);
    }

    /** The labeler and product segments (the drug without its package) of a hyphenated NDC, zero-normalized. */
    public static function productKey(string $ndc): ?string
    {
        $segments = explode('-', $ndc);
        if (count($segments) !== 3) {
            return null;
        }

        return str_pad($segments[0], 5, '0', STR_PAD_LEFT).'-'.str_pad($segments[1], 4, '0', STR_PAD_LEFT);
    }

    /** @param list<int> $layout */
    private static function join(string $digits, array $layout): string
    {
        $parts = [];
        $offset = 0;
        foreach ($layout as $length) {
            $parts[] = substr($digits, $offset, $length);
            $offset += $length;
        }

        return implode('-', $parts);
    }
}
