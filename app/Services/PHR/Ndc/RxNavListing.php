<?php

namespace App\Services\PHR\Ndc;

/**
 * Reads the raw RxNav responses that RxNavClient keeps (and a fill stores in its
 * snapshot): which RxCUI an NDC means, the NDC's SPL properties, its ingredients
 * with strengths, its dose form and route, and whether the product is still marketed.
 * Pure parsing, no I/O.
 *
 * The bundle is the client's entry: `ndcstatus`, `ndcproperties` and `related`
 * are the unmodified response bodies.
 */
final class RxNavListing
{
    /** The marker a fill's snapshot carries when RxNav, not openFDA, answered. */
    public const string SOURCE = 'rxnav';

    /**
     * Dose-form words that name a route. RxNorm writes the route inside the form ("Oral Tablet"),
     * where openFDA lists them apart.
     */
    private const array ROUTE_WORDS = [
        'oral', 'topical', 'ophthalmic', 'otic', 'nasal', 'rectal', 'vaginal', 'sublingual', 'buccal',
        'transdermal', 'urethral', 'intrauterine', 'intravitreal', 'intravesical', 'intratracheal',
    ];

    /** Dose-form modifiers that come first in RxNorm ("Extended Release Oral Tablet") and last in openFDA ("tablet, extended release"). */
    private const array FORM_MODIFIERS = [
        'extended release', 'delayed release', 'disintegrating', 'chewable', 'effervescent', 'film coated', 'sustained release',
    ];

    /**
     * The RxCUI to describe an NDC with, or null when RxNorm has no usable concept for it.
     * The NDC's own concept is used while it is current; otherwise the concept it was last
     * moved to. An NDC that is not an RxNorm NDC (a VA-only code, say) is not used.
     *
     * @param  array<string, mixed>  $statusBody  the ndcstatus response
     */
    public static function usableRxcui(array $statusBody): ?string
    {
        $status = $statusBody['ndcStatus'] ?? null;
        if (! is_array($status) || strtoupper((string) ($status['rxnormNdc'] ?? '')) !== 'YES') {
            return null;
        }
        $rxcui = trim((string) ($status['rxcui'] ?? ''));
        if ($rxcui !== '' && strtoupper((string) ($status['conceptStatus'] ?? '')) === 'ACTIVE') {
            return $rxcui;
        }

        $latest = null;
        foreach ((array) ($status['ndcHistory'] ?? []) as $period) {
            $active = is_array($period) ? trim((string) ($period['activeRxcui'] ?? '')) : '';
            if ($active !== '' && ($latest === null || (string) ($period['startDate'] ?? '') >= $latest['start'])) {
                $latest = ['start' => (string) ($period['startDate'] ?? ''), 'rxcui' => $active];
            }
        }

        return $latest['rxcui'] ?? null;
    }

    /** @param array<string, mixed> $related the related response */
    public static function hasConcepts(array $related): bool
    {
        return self::concepts($related, 'SCDC') !== [] || self::concepts($related, 'IN') !== [];
    }

    /**
     * The name of the concept the NDC maps to, as RxNorm writes it.
     *
     * @param  array<string, mixed>  $bundle
     */
    public static function conceptName(array $bundle): ?string
    {
        $name = $bundle['ndcstatus']['ndcStatus']['conceptName'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * A brand-name product is written "ingredient strength form [Brand]". A generic has no brackets,
     * so a brand name is never taken from the brands that merely share its ingredients.
     *
     * @param  array<string, mixed>  $bundle
     */
    public static function brandName(array $bundle): ?string
    {
        return preg_match('/\[([^\]]+)\]\s*\z/', (string) self::conceptName($bundle), $match) === 1 ? trim($match[1]) : null;
    }

    /**
     * Each ingredient with its own strength, in the order the concept name writes them.
     * Falls back to the bare ingredients (no strengths) when RxNorm lists no strength concepts.
     *
     * @param  array<string, mixed>  $bundle
     * @return list<array{name: string, strength: ?string}>
     */
    public static function ingredients(array $bundle): array
    {
        $related = (array) ($bundle['related'] ?? []);
        $conceptName = mb_strtolower((string) self::conceptName($bundle));
        $rows = [];
        foreach (self::concepts($related, 'SCDC') as $position => $name) {
            [$ingredient, $strength] = self::splitStrength($name);
            $at = $conceptName === '' ? false : mb_strpos($conceptName, mb_strtolower($ingredient));
            $rows[] = ['name' => $ingredient, 'strength' => $strength, 'order' => $at === false ? PHP_INT_MAX : $at, 'position' => $position];
        }
        if ($rows === []) {
            foreach (self::concepts($related, 'IN') as $position => $name) {
                $rows[] = ['name' => $name, 'strength' => null, 'order' => $position, 'position' => $position];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['order'], $a['position']] <=> [$b['order'], $b['position']]);

        return array_map(static fn (array $row): array => ['name' => $row['name'], 'strength' => $row['strength']], $rows);
    }

    /**
     * The dose form and route RxNorm's one combined form means: "Extended Release Oral Tablet" is the
     * form "tablet, extended release" by the "oral" route. A form with no route word ("Injectable
     * Solution", "Chewable Tablet") has no route.
     *
     * @param  array<string, mixed>  $bundle
     * @return array{0: ?string, 1: ?string} dose form, route (as written by RxNorm, not yet cased)
     */
    public static function doseFormAndRoute(array $bundle): array
    {
        $form = self::concepts((array) ($bundle['related'] ?? []), 'DF')[0] ?? null;
        if ($form === null) {
            return [null, null];
        }
        $words = preg_split('/\s+/', mb_strtolower(trim($form))) ?: [];
        $route = null;
        foreach ($words as $index => $word) {
            if (in_array($word, self::ROUTE_WORDS, true)) {
                $route = $word;
                array_splice($words, $index, 1);
                break;
            }
        }
        $rest = implode(' ', $words);
        $modifiers = [];
        do {
            $matched = false;
            foreach (self::FORM_MODIFIERS as $modifier) {
                if (str_starts_with($rest, $modifier.' ')) {
                    $modifiers[] = ($modifier === 'disintegrating' && $route === 'oral') ? 'orally disintegrating' : $modifier;
                    $rest = substr($rest, strlen($modifier) + 1);
                    $matched = true;
                }
            }
        } while ($matched);

        return [$rest === '' ? null : implode(', ', [$rest, ...$modifiers]), $route];
    }

    /**
     * The SPL property list of the NDC: the entry for the RxCUI in use, else the first.
     *
     * @param  array<string, mixed>  $bundle
     * @return array{values: array<string, string>, ndc9: ?string, ndc10: ?string, packaging: list<string>, spl_set_id: ?string}
     */
    public static function properties(array $bundle): array
    {
        $items = (array) ($bundle['ndcproperties']['ndcPropertyList']['ndcProperty'] ?? []);
        $items = array_values(array_filter($items, is_array(...)));
        $item = null;
        foreach ($items as $candidate) {
            if ((string) ($candidate['rxcui'] ?? '') === (string) ($bundle['rxcui'] ?? '')) {
                $item = $candidate;
                break;
            }
        }
        $item ??= $items[0] ?? [];

        $values = [];
        foreach ((array) ($item['propertyConceptList']['propertyConcept'] ?? []) as $property) {
            if (is_array($property) && isset($property['propName']) && is_scalar($property['propValue'] ?? null)) {
                $values[(string) $property['propName']] ??= trim((string) $property['propValue']);
            }
        }
        $packaging = $item['packagingList']['packaging'] ?? [];
        $packaging = array_values(array_filter(is_array($packaging) ? $packaging : [$packaging], static fn (mixed $p): bool => is_string($p) && trim($p) !== ''));

        return [
            'values' => $values,
            'ndc9' => is_string($item['ndc9'] ?? null) ? $item['ndc9'] : null,
            'ndc10' => is_string($item['ndc10'] ?? null) ? $item['ndc10'] : null,
            'packaging' => $packaging,
            'spl_set_id' => is_string($item['splSetIdItem'] ?? null) ? $item['splSetIdItem'] : null,
        ];
    }

    /**
     * The package description that names this package, else the first listed.
     *
     * @param  array<string, mixed>  $bundle
     */
    public static function packageDescription(array $bundle, string $ndc10): ?string
    {
        $packaging = self::properties($bundle)['packaging'];
        foreach ($packaging as $description) {
            if (str_contains($description, '('.$ndc10.')')) {
                return $description;
            }
        }

        return $packaging[0] ?? null;
    }

    /**
     * Whether the product is off the market. The SPL marketing status decides when RxNorm has
     * one ("active" is marketed; completed, deleted and the rest are not); without it the NDC's
     * own RxNorm activity flag does.
     *
     * @param  array<string, mixed>|null  $snapshot  a fill's stored snapshot (the client's bundle)
     */
    public static function isDiscontinued(?array $snapshot): bool
    {
        if (! is_array($snapshot)) {
            return false;
        }
        $marketing = self::properties($snapshot)['values']['MARKETING_STATUS'] ?? '';
        if ($marketing !== '') {
            return strtolower($marketing) !== 'active';
        }

        return strtoupper((string) ($snapshot['ndcstatus']['ndcStatus']['active'] ?? '')) === 'NO';
    }

    /**
     * The labeler-product code ("0093-0150"), the same shape openFDA gives as product_ndc.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function productNdc(?array $snapshot): ?string
    {
        return is_array($snapshot) ? self::properties($snapshot)['ndc9'] : null;
    }

    /**
     * @param  array<string, mixed>  $related
     * @return list<string> concept names of one term type, in the order RxNav lists them
     */
    private static function concepts(array $related, string $tty): array
    {
        $names = [];
        foreach ((array) ($related['relatedGroup']['conceptGroup'] ?? []) as $group) {
            if (! is_array($group) || ($group['tty'] ?? null) !== $tty) {
                continue;
            }
            foreach ((array) ($group['conceptProperties'] ?? []) as $concept) {
                if (is_array($concept) && is_string($concept['name'] ?? null) && trim($concept['name']) !== '') {
                    $names[] = trim($concept['name']);
                }
            }
        }

        return $names;
    }

    /**
     * "codeine phosphate 30 MG" is the ingredient "codeine phosphate" at "30 MG". The strength is the
     * trailing number-and-unit, so a number inside the name ("polysorbate 80 10 MG/ML") stays in it.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function splitStrength(string $name): array
    {
        $words = explode(' ', $name);
        for ($index = 1; $index < count($words); $index++) {
            $tail = implode(' ', array_slice($words, $index));
            if (preg_match('#\A\d[\d.,]*\s?[A-Za-z%\[]\S*(?:\s[A-Za-z%\[]\S*)?\z#', $tail) === 1) {
                return [implode(' ', array_slice($words, 0, $index)), $tail];
            }
        }

        return [$name, null];
    }
}
