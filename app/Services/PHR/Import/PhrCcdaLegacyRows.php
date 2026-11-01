<?php

namespace App\Services\PHR\Import;

use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Moves rows an earlier import made of a C-CDA section's entries onto the ids
 * the structured reader gives them, so a re-import updates them in place. The
 * same rule as PhrCcdaLegacyLabRows, for every other section.
 *
 * The upserter matches on (patient, import source, external id) only, and two
 * earlier writers gave the same facts other ids:
 *
 * - the narrative-table reader, under the same import source, with a hash of
 *   the table row (PhrCcdaSectionReader::narrativeId); the entry's reference
 *   into that row recomputes it;
 * - an earlier load, under its own import source, as `<label>:<kind>:<key>`,
 *   where the key is the entry's `root|extension` or its extension alone.
 *
 * Only the row's identity is moved here. The importer's upsert then writes the
 * facts with its usual rule: a change reopens review, an unchanged row keeps the
 * review it has.
 *
 * When one entry matches more than one earlier row, the narrative-table row
 * (this importer's own) is moved, else the earliest earlier-load row. The others
 * are neither merged nor deleted: they keep their identity and content and are
 * set to pending review, so a person decides whether they are duplicates. That
 * happens only when a row is moved; once an entry's id exists, rows left under
 * old ids are not touched again, so a person's decision about them stands.
 *
 * External ids are compared in PHP, byte for byte: they are case-sensitive in
 * production, and a case-insensitive collation must not make two ids match.
 * Withdrawn (soft-deleted) rows are not adopted.
 */
final class PhrCcdaLegacyRows
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<PhrCcdaEntry>  $entries  the section's entries of the model's own record type
     */
    public function adopt(string $modelClass, int $patientId, string $importSource, string $kind, array $entries): void
    {
        $separator = ':'.$kind.':';
        $rows = $modelClass::query()
            ->where('patient_id', $patientId)
            ->orderBy('id')
            ->get(['id', 'import_source', 'external_id', 'review_status']);

        /** @var array<string, Model> $narrative */
        $narrative = [];
        /** @var array<string, list<Model>> $loaded */
        $loaded = [];
        foreach ($rows as $row) {
            $externalId = $row->getAttribute('external_id');
            if (! is_string($externalId)) {
                continue;
            }
            if ($row->getAttribute('import_source') === $importSource) {
                $narrative[$externalId] = $row;
            }
            $at = strpos($externalId, $separator);
            if ($at !== false) {
                $loaded[substr($externalId, $at + strlen($separator))][] = $row;
            }
        }

        // Every id this import source already holds, withdrawn rows included:
        // the unique index covers them. These models' only global scope is the
        // soft-delete one.
        $taken = [];
        foreach ($modelClass::query()->withoutGlobalScopes()->where('patient_id', $patientId)->get(['import_source', 'external_id']) as $row) {
            $externalId = $row->getAttribute('external_id');
            if ($row->getAttribute('import_source') === $importSource && is_string($externalId)) {
                $taken[$externalId] = true;
            }
        }

        $claimed = [];
        foreach ($entries as $entry) {
            $externalId = $entry->record['external_id'] ?? null;
            if (! is_string($externalId) || isset($taken[$externalId])) {
                continue;
            }

            // The narrative-table row first, then earlier-load rows, earliest first.
            /** @var array<int|string, Model> $earlier */
            $earlier = [];
            foreach ($entry->sourceIds as $sourceId) {
                foreach ($loaded[$sourceId] ?? [] as $row) {
                    $earlier[$row->getKey()] = $row;
                }
            }
            ksort($earlier);
            $candidates = $entry->narrativeId !== null && isset($narrative[$entry->narrativeId]) ? [$narrative[$entry->narrativeId]] : [];
            $candidates = array_values(array_filter([...$candidates, ...array_values($earlier)], static fn (Model $row): bool => ! isset($claimed[$row->getKey()])));
            if ($candidates === []) {
                continue;
            }

            foreach ($candidates as $row) {
                $claimed[$row->getKey()] = true;
            }
            $adopted = array_shift($candidates);
            $modelClass::query()->whereKey($adopted->getKey())->update(['import_source' => $importSource, 'external_id' => $externalId]);
            $taken[$externalId] = true;

            $others = array_map(static fn (Model $row): mixed => $row->getKey(), $candidates);
            if ($others !== []) {
                $modelClass::query()->whereKey($others)
                    ->where('review_status', '!=', PhrReviewStatus::PENDING)
                    ->update(['review_status' => PhrReviewStatus::PENDING]);
            }
        }
    }
}
