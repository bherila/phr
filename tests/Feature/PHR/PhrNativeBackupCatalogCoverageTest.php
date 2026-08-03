<?php

namespace Tests\Feature\PHR;

use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use App\Support\Storage\PhrStorageMap;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fails whenever a migration makes another table reachable from phr_patients without
 * deliberately classifying it in the native-backup contract.
 *
 * If this fails, do NOT simply add the table to excluded(). Determine whether its rows
 * are authoritative, whether it carries artifacts, and which direct patient key scopes
 * it. A casual exclusion turns a green build into a silently incomplete backup.
 */
class PhrNativeBackupCatalogCoverageTest extends TestCase
{
    public function test_every_patient_reachable_table_is_included_or_excluded_with_a_reason(): void
    {
        $reachable = ['phr_patients' => true];
        $foreignKeys = [];
        foreach (Schema::getTables() as $table) {
            $foreignKeys[$table['name']] = Schema::getForeignKeys($table['name']);
        }

        do {
            $changed = false;
            foreach ($foreignKeys as $table => $keys) {
                if (isset($reachable[$table])) {
                    continue;
                }
                foreach ($keys as $key) {
                    if (isset($reachable[$key['foreign_table']])) {
                        $reachable[$table] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        $accounted = array_keys(PhrNativeBackupCatalog::included() + PhrNativeBackupCatalog::excluded());
        $discovered = array_keys($reachable);
        sort($accounted);
        sort($discovered);

        $this->assertSame($discovered, $accounted, sprintf(
            "Patient-reachable tables and PhrNativeBackupCatalog differ.\nDiscovered: %s\nAccounted: %s",
            implode(', ', $discovered),
            implode(', ', $accounted),
        ));

        foreach (PhrNativeBackupCatalog::excluded() as $table => $definition) {
            $this->assertNotSame('', trim($definition['because']), "Excluded table {$table} needs a substantive reason.");
        }
    }

    public function test_every_catalog_scope_is_the_direct_patient_foreign_key(): void
    {
        foreach (PhrNativeBackupCatalog::included() + PhrNativeBackupCatalog::excluded() as $table => $definition) {
            $this->assertTrue(Schema::hasColumn($table, $definition['patient_column']));
            if ($table === 'phr_patients') {
                $this->assertSame('id', $definition['patient_column']);

                continue;
            }

            $directPatientColumns = [];
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] === 'phr_patients') {
                    array_push($directPatientColumns, ...$foreignKey['columns']);
                }
            }

            $this->assertContains(
                $definition['patient_column'],
                $directPatientColumns,
                "{$table} must be scoped by its direct phr_patients foreign key, not the first transitive relationship.",
            );
        }
    }

    public function test_every_backup_artifact_table_is_also_protected_by_the_storage_map(): void
    {
        $storageReferences = array_map(
            static fn ($reference): string => $reference->label(),
            PhrStorageMap::references()->references(),
        );

        foreach (PhrNativeBackupCatalog::artifactBearingTables() as $table => $column) {
            $this->assertContains(
                $table.'.'.$column,
                $storageReferences,
                "{$table}.{$column} is archived but not protected by PhrStorageMap.",
            );
        }
    }
}
