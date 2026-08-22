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
    /** The agent-writable clinical tables whose upsert identity uses external_id. */
    private const array TABLES = [
        'phr_lab_results',
        'phr_patient_vitals',
        'phr_office_visits',
        'phr_medications',
        'phr_conditions',
        'phr_procedures',
        'phr_immunizations',
        'phr_allergies',
    ];

    public function up(): void
    {
        $this->applyCollation('utf8mb4_bin');
    }

    public function down(): void
    {
        $this->applyCollation((string) (config('database.connections.'.DB::getDefaultConnection().'.collation') ?: 'utf8mb4_unicode_ci'));
    }

    private function applyCollation(string $collation): void
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

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `external_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE %s NULL',
                $tableName,
                $collation,
            ));
        }
    }
};
