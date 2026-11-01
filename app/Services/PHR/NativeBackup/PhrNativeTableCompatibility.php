<?php

namespace App\Services\PHR\NativeBackup;

/**
 * Keeps archives written before a catalog table existed restorable.
 *
 * The archive reader requires the manifest's table set to equal the current
 * catalog. Adding a table to `PhrNativeBackupCatalog::included()` therefore makes
 * every archive a user has already downloaded unreadable, while a round-trip test
 * stays green because it builds and reads against the same catalog. The frozen
 * fixture in `PhrNativeRestoreTest` is what holds this in place.
 *
 * A table added after v1 shipped belongs to exactly one generation, and a
 * generation is either wholly present or wholly absent: a build that knew the
 * tables wrote all of them, so a partial set is a damaged or forged archive, not
 * an old one. A wholly absent generation reads as empty tables -- what such a
 * patient had before the tables existed. Generations are listed oldest first and
 * an archive predates a generation only if it also predates every later one. Any
 * other difference (an unknown table, a missing original v1 table) still fails
 * closed, and SCHEMA_VERSION stays 1.
 */
final class PhrNativeTableCompatibility
{
    /** @var array<string, list<string>> generation => tables, in catalog order */
    public const array LATER_GENERATIONS = [
        'ehi_tables' => [
            'phr_ehi_sources',
            'phr_ehi_packages',
            'phr_ehi_blobs',
            'phr_ehi_package_members',
            'phr_ehi_table_accounts',
            'phr_ehi_findings',
        ],
        'ehi_evidence' => [
            'phr_ehi_entities',
            'phr_ehi_revisions',
            'phr_ehi_occurrences',
            'phr_ehi_proposals',
            'phr_ehi_proposal_revisions',
            'phr_ehi_proposal_decisions',
            'phr_ehi_record_links',
        ],
        // Prescription fills (phr#265). Archives downloaded before they existed have none.
        'medication_fills' => [
            'phr_medication_fills',
        ],
        // The evidence that more than one source shows a fill (phr#268).
        'medication_fill_evidence' => [
            'phr_medication_fill_evidence',
        ],
    ];

    /**
     * Catalog tables this archive predates. Throws for any other mismatch.
     *
     * @param  list<int|string>  $archivedTables
     * @return list<string>
     */
    public static function absentTables(array $archivedTables): array
    {
        $expected = array_keys(PhrNativeBackupCatalog::included());
        $archived = array_map('strval', $archivedTables);
        $missing = array_values(array_diff($expected, $archived));
        if (array_diff($archived, $expected) !== []) {
            throw new NativeRestoreException('invalid_archive');
        }

        $tolerated = [];
        foreach (self::LATER_GENERATIONS as $tables) {
            $absent = array_intersect($tables, $missing);
            if ($absent === []) {
                // No build wrote a later generation without every earlier one.
                if ($tolerated !== []) {
                    throw new NativeRestoreException('invalid_archive');
                }

                continue;
            }
            if (count($absent) !== count($tables)) {
                throw new NativeRestoreException('invalid_archive');
            }
            array_push($tolerated, ...$tables);
        }
        if (array_diff($missing, $tolerated) !== []) {
            throw new NativeRestoreException('invalid_archive');
        }

        return $missing;
    }

    /** Whether an absent manifest entry for this table may be read as empty. */
    public static function readsAsEmpty(string $table): bool
    {
        foreach (self::LATER_GENERATIONS as $tables) {
            if (in_array($table, $tables, true)) {
                return true;
            }
        }

        return false;
    }
}
