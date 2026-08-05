<?php

namespace Tests\Feature\Storage;

use App\Support\Storage\PhrStorageMap;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fails when a column that could hold a storage key is neither mapped nor explicitly
 * ignored.
 *
 * The pruner deletes anything no mapped column references. So an unmapped column is not
 * a gap in coverage — it is a set of files scheduled for deletion. This test converts
 * "someone added a file-bearing table" from silent data loss into a red build.
 *
 * If this fails, do NOT simply add the column to ignoring(). Work out whether it holds a
 * storage key; only genuinely key-shaped-but-not-a-key columns belong there.
 */
class BlobReferencesCoverageTest extends TestCase
{
    /**
     * Column names worth interrogating. Broad on purpose — a false positive costs one
     * line in ignoring(), a false negative costs files.
     */
    private const SUSPICIOUS = '/(s3_?path|storage_path|r2_key|r2_prefix|object_key|_key$|_path$|^path$)/i';

    public function test_every_storage_key_column_is_mapped_or_explicitly_ignored(): void
    {
        $map = PhrStorageMap::references();

        $mapped = array_map(
            fn ($reference) => $reference->label(),
            $map->references(),
        );
        $accounted = array_flip([...$mapped, ...$map->ignoredColumns()]);

        $unaccounted = [];

        foreach (Schema::getTables() as $table) {
            $tableName = $table['name'];

            foreach (Schema::getColumns($tableName) as $column) {
                $label = $tableName.'.'.$column['name'];

                if (! preg_match(self::SUSPICIOUS, $column['name'])) {
                    continue;
                }

                if (! isset($accounted[$label])) {
                    $unaccounted[] = $label;
                }
            }
        }

        sort($unaccounted);

        $this->assertSame([], $unaccounted, sprintf(
            "These columns look like storage keys but are neither mapped nor ignored in PhrStorageMap:\n  %s\n\n".
            'Until one of those happens, the pruner treats every object they reference as garbage.',
            implode("\n  ", $unaccounted),
        ));
    }

    public function test_mapped_tables_and_columns_actually_exist(): void
    {
        foreach (PhrStorageMap::references()->references() as $reference) {
            $this->assertTrue(
                Schema::hasTable($reference->table),
                "Mapped table {$reference->table} does not exist — the map has drifted from the schema.",
            );
            $this->assertTrue(
                Schema::hasColumn($reference->table, $reference->column),
                "Mapped column {$reference->label()} does not exist — the map has drifted from the schema.",
            );
            foreach (array_keys($reference->conditions) as $conditionColumn) {
                $this->assertTrue(
                    Schema::hasColumn($reference->table, $conditionColumn),
                    "Mapped condition {$reference->table}.{$conditionColumn} does not exist — the map has drifted from the schema.",
                );
            }
        }
    }
}
