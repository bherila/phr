<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the agent-facing external ID a case-sensitive identifier.
 *
 * `external_id` was created as a plain string, so on MySQL/MariaDB it inherits
 * the connection collation -- `utf8mb4_unicode_ci` by default. That made the
 * upsert identity `(patient_id, import_source, external_id)` case- and
 * accent-insensitive in production while remaining bytewise in SQLite, so tests
 * and production disagreed about what "the same record" means.
 *
 * It also made resolution self-contradictory: a case-insensitive index hit is
 * keyed into the response under its stored spelling, while the unresolved list
 * is computed with a case-sensitive PHP lookup, so one requested ID could come
 * back resolved under a key the caller never sent *and* listed as unresolved.
 *
 * External IDs are opaque client-chosen identifiers, so bytewise comparison is
 * the least surprising contract. This narrows equality, so the existing unique
 * indexes cannot gain a collision from it and the ALTER cannot fail on
 * duplicates.
 */
return new class extends Migration
{
    /**
     * Tables whose agent write path keys identity on external_id.
     *
     * The eight clinical resources reach it through `*.upsert`; `phr_documents`
     * carries the identical `(patient_id, import_source, external_id)` unique
     * index and reaches it through `documents.upload`, where a case-folded
     * match is read as either an idempotent retry or an identifier conflict.
     *
     * `phr_eobs`, `phr_portal_messages`, and `phr_negative_assertions` share the
     * column shape but have no agent write path keyed on it, so changing their
     * import identity is deliberately left to a separate audit.
     */
    private const array TABLES = [
        'phr_lab_results',
        'phr_patient_vitals',
        'phr_office_visits',
        'phr_medications',
        'phr_conditions',
        'phr_procedures',
        'phr_immunizations',
        'phr_allergies',
        'phr_documents',
    ];

    private const string TARGET_COLLATION = 'utf8mb4_bin';

    public function up(): void
    {
        // SQLite compares text bytewise already, and Postgres has no per-column
        // collation of this shape. Only the MySQL family needs the override.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'external_id')) {
                continue;
            }

            $column = $this->currentColumn($tableName);
            // A column that is already binary needs no DDL at all. Skipping it
            // keeps a redeploy, or a database provisioned with a binary default
            // collation, from taking an avoidable table lock.
            if ($column === null || strcasecmp((string) $column->collation, self::TARGET_COLLATION) === 0) {
                continue;
            }

            // Type and nullability are carried over from the live column rather
            // than assumed, so this cannot silently widen or narrow either.
            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `external_id` %s CHARACTER SET utf8mb4 COLLATE %s %s',
                $tableName,
                $column->type,
                self::TARGET_COLLATION,
                $column->nullable === 'YES' ? 'NULL' : 'NOT NULL',
            ));
        }
    }

    public function down(): void
    {
        // Deliberate no-op. Once external IDs compare bytewise, a client may
        // legitimately hold both `Medication-ABC` and `medication-abc`, and
        // restoring a case-insensitive collation could then violate the unique
        // identity indexes. It would also reintroduce the production/SQLite
        // disagreement this migration exists to remove. The binary column is
        // backward compatible with the pre-migration application code, so a
        // code rollback does not require reversing it.
    }

    private function currentColumn(string $tableName): ?object
    {
        $column = DB::selectOne(
            'SELECT COLLATION_NAME AS collation, COLUMN_TYPE AS type, IS_NULLABLE AS nullable
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tableName, 'external_id'],
        );

        return is_object($column) ? $column : null;
    }
};
