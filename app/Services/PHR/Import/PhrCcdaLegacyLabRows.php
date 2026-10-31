<?php

namespace App\Services\PHR\Import;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;

/**
 * Moves lab rows an earlier import made of a C-CDA's results onto the ids the
 * structured reader gives them, so a re-import updates them in place.
 *
 * The upserter matches on (patient, import source, external id) only, and two
 * earlier writers gave the same results other ids:
 *
 * - the narrative-table reader, under the same import source, with a hash of
 *   the table row (PhrCcdaNarrativeRow::labExternalId); the observation's
 *   reference into that row recomputes it;
 * - an earlier bulk load, under its own import source, as
 *   `<label>:lab:<root>|<extension>` of the observation's id.
 *
 * Without this a re-import adds a second set of rows and leaves the old ones,
 * with their wrong ranges, beside them.
 *
 * Only the row's identity is moved here. The importer's upsert then writes the
 * facts with its usual rule: a change reopens review, an unchanged row keeps
 * the review it has.
 *
 * When one observation matches more than one earlier row, the narrative-table
 * row (this importer's own) is moved, else the earliest earlier-load row. The
 * others are neither merged nor deleted: they keep their identity and content
 * and are set to pending review, so a person decides whether they are
 * duplicates. That happens only when a row is moved; once an observation's id
 * exists, rows left under old ids are not touched again, so a person's decision
 * about them stands.
 *
 * External ids are compared in PHP, byte for byte: they are case-sensitive in
 * production, and a case-insensitive collation must not make two ids match.
 * Withdrawn (soft-deleted) rows are not adopted.
 */
final class PhrCcdaLegacyLabRows
{
    private const string LOAD_SEPARATOR = ':lab:';

    /**
     * @param  list<array{record: array<string, string|null>|null, narrative_id: string|null, source_id: string|null, narrative_cells: list<string>|null}>  $observations
     */
    public function adopt(PhrPatient $patient, string $importSource, array $observations): void
    {
        $rows = PhrLabResult::query()
            ->where('patient_id', $patient->id)
            ->orderBy('id')
            ->get(['id', 'import_source', 'external_id', 'review_status']);

        /** @var array<string, PhrLabResult> $narrative */
        $narrative = [];
        /** @var array<string, list<PhrLabResult>> $loaded */
        $loaded = [];
        foreach ($rows as $row) {
            $externalId = $row->external_id;
            if (! is_string($externalId)) {
                continue;
            }
            if ($row->import_source === $importSource) {
                $narrative[$externalId] = $row;
            }
            $separator = strpos($externalId, self::LOAD_SEPARATOR);
            if ($separator !== false) {
                $loaded[substr($externalId, $separator + strlen(self::LOAD_SEPARATOR))][] = $row;
            }
        }

        // Every id this import source already holds, withdrawn rows included:
        // the unique index covers them.
        $taken = [];
        foreach (PhrLabResult::withTrashed()->where('patient_id', $patient->id)->get(['import_source', 'external_id']) as $row) {
            if ($row->import_source === $importSource && is_string($row->external_id)) {
                $taken[$row->external_id] = true;
            }
        }

        $claimed = [];
        foreach ($observations as $observation) {
            $externalId = $observation['record']['external_id'] ?? null;
            if (! is_string($externalId) || isset($taken[$externalId])) {
                continue;
            }

            $candidates = [];
            if ($observation['narrative_id'] !== null && isset($narrative[$observation['narrative_id']])) {
                $candidates[] = $narrative[$observation['narrative_id']];
            }
            foreach ($observation['source_id'] === null ? [] : ($loaded[$observation['source_id']] ?? []) as $row) {
                $candidates[] = $row;
            }
            $candidates = array_values(array_filter($candidates, static fn (PhrLabResult $row): bool => ! isset($claimed[$row->id])));
            if ($candidates === []) {
                continue;
            }

            foreach ($candidates as $row) {
                $claimed[$row->id] = true;
            }
            $adopted = array_shift($candidates);
            PhrLabResult::query()->whereKey($adopted->id)->update(['import_source' => $importSource, 'external_id' => $externalId]);
            $taken[$externalId] = true;

            $others = array_map(static fn (PhrLabResult $row): int => (int) $row->id, $candidates);
            if ($others !== []) {
                PhrLabResult::query()->whereKey($others)
                    ->where('review_status', '!=', PhrReviewStatus::PENDING)
                    ->update(['review_status' => PhrReviewStatus::PENDING]);
            }
        }
    }
}
