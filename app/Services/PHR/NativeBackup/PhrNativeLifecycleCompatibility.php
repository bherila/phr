<?php

namespace App\Services\PHR\NativeBackup;

use Illuminate\Support\Facades\Schema;

/**
 * Keeps archives written before the record lifecycle restorable.
 *
 * The archive reader derives a record's expected attribute set from the table's
 * *current* columns and compares it exactly, and the restore planner compares an
 * archive record's content hash against a hash of the current row. Adding
 * `deleted_at` and `retracted_at` therefore did two things to every archive a
 * user had already downloaded: its records no longer matched the expected
 * attribute set, and an otherwise identical row hashed differently.
 *
 * Neither shows up in a round-trip test, because those build and restore against
 * the same schema and so always agree. That is exactly why this needs a frozen
 * fixture rather than another round trip.
 *
 * A missing lifecycle attribute is read as null -- the value such a row had
 * before the columns existed. It is normalized *after* the stored content hash
 * has been verified against the record's original attributes, so archive
 * integrity is still checked against what was actually written. A row that has
 * since been deleted or retracted still hashes differently from the archived
 * one and stays a conflict rather than being silently resurrected.
 */
final class PhrNativeLifecycleCompatibility
{
    public const array COLUMNS = ['deleted_at', 'retracted_at'];

    /** @var array<string, list<string>> */
    private static array $columnsByTable = [];

    /**
     * Lifecycle columns this table has now that a legacy archive may predate.
     *
     * @return list<string>
     */
    public static function columnsFor(string $table): array
    {
        return self::$columnsByTable[$table] ??= array_values(array_intersect(
            self::COLUMNS,
            Schema::getColumnListing($table),
        ));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function normalize(string $table, array $record): array
    {
        $attributes = $record['attributes'] ?? null;
        if (! is_array($attributes) || ! is_array($record['relationships'] ?? null)) {
            return $record;
        }

        $missing = array_diff(self::columnsFor($table), array_keys($attributes));
        if ($missing === []) {
            return $record;
        }

        foreach ($missing as $column) {
            $attributes[$column] = null;
        }

        // Re-derive the hash so downstream comparison against a current row is
        // like-for-like. The stored hash has already been verified against the
        // original attributes by the reader.
        $normalized = (new PhrNativeRecordCodec)->record(
            (string) $record['nativeId'],
            $attributes,
            $record['relationships'],
        );

        return [...$record, 'attributes' => $normalized['attributes'], 'contentHash' => $normalized['contentHash']];
    }

    /** Test seam: the column listing is memoized per process. */
    public static function flush(): void
    {
        self::$columnsByTable = [];
    }
}
