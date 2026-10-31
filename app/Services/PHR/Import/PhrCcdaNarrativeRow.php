<?php

namespace App\Services\PHR\Import;

use DOMElement;
use DOMXPath;

/**
 * One row of a C-CDA section's narrative table, as the narrative-table reader
 * sees it.
 *
 * The external id a lab row read from that table gets is a hash of these cells.
 * Results read from structured entries recompute it to find the row an earlier
 * import made of the same table row (PhrCcdaLegacyLabRows), so both readers
 * must take the cells from here.
 */
final class PhrCcdaNarrativeRow
{
    /**
     * @return list<string>
     */
    public static function cells(DOMXPath $xpath, DOMElement $tr): array
    {
        $cells = [];
        foreach ($xpath->query('./*[local-name()="td" or local-name()="th"]', $tr) ?: [] as $cell) {
            $cells[] = preg_replace('/\s+/', ' ', trim($cell->textContent)) ?: '';
        }

        return $cells;
    }

    /**
     * Best-effort header-row skip. CCDA narrative tables vary by vendor and have no
     * stable scope/role markers, so this matches a small set of common English labels;
     * localized exports or non-standard headers will fall through and import as data.
     *
     * @param  array<int, string>  $cells
     */
    public static function isData(array $cells): bool
    {
        if ($cells === []) {
            return false;
        }

        $first = strtolower($cells[0]);

        return ! in_array($first, ['date', 'name', 'test', 'vaccine', 'medication', 'substance'], true);
    }

    /**
     * @param  array<int, string>  $cells
     */
    public static function labExternalId(array $cells): string
    {
        return 'ccda-lab-'.sha1(implode('|', $cells));
    }
}
