<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health logs and their entries are soft-deleted (#444), so an agent's repeated
 * delete answers `unchanged` and changes.list reports a tombstone.
 *
 * A log's name is unique for its patient, and a person must be able to reuse the
 * name of a log they deleted. The unique index therefore covers
 * (patient_id, name, name_reserved): `name_reserved` is true while the log stands
 * and null once it is deleted. Every supported database (MariaDB, SQLite and
 * PostgreSQL) treats NULLs as distinct in a unique index, so a deleted log never
 * collides with anything, while two standing logs still cannot share a name. It is
 * an ordinary column, not a generated one: SQLite cannot add a stored generated
 * column to an existing table, PostgreSQL before 18 cannot have a virtual one, and a
 * native backup restores plain columns as written. PhrHealthLog keeps it in step
 * with deleted_at.
 */
return new class extends Migration
{
    private const string OLD_UNIQUE = 'phr_hl_patient_name_uid';

    private const string NEW_UNIQUE = 'phr_hl_patient_live_name_uid';

    public function up(): void
    {
        Schema::table('phr_health_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('phr_health_logs', 'name_reserved')) {
                $table->boolean('name_reserved')->nullable()->default(true)->after('name');
            }
            if (! Schema::hasColumn('phr_health_logs', 'deleted_at')) {
                $table->softDeletes();
            }
        });
        Schema::table('phr_health_log_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('phr_health_log_entries', 'deleted_at')) {
                $table->softDeletes();
            }
        });
        // The new index first, so the patient foreign key is never without one.
        if (! Schema::hasIndex('phr_health_logs', self::NEW_UNIQUE)) {
            Schema::table('phr_health_logs', function (Blueprint $table): void {
                $table->unique(['patient_id', 'name', 'name_reserved'], self::NEW_UNIQUE);
            });
        }
        if (Schema::hasIndex('phr_health_logs', self::OLD_UNIQUE)) {
            Schema::table('phr_health_logs', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        // Dropping the columns would bring every deleted log and entry back, and a
        // reused name would break the old unique index.
        foreach (['phr_health_logs', 'phr_health_log_entries'] as $tableName) {
            if (Schema::hasColumn($tableName, 'deleted_at') && DB::table($tableName)->whereNotNull('deleted_at')->exists()) {
                throw new RuntimeException("Refusing to drop {$tableName}.deleted_at: deleted health log records would become visible again.");
            }
        }
        if (! Schema::hasIndex('phr_health_logs', self::OLD_UNIQUE)) {
            Schema::table('phr_health_logs', function (Blueprint $table): void {
                $table->unique(['patient_id', 'name'], self::OLD_UNIQUE);
            });
        }
        Schema::table('phr_health_logs', function (Blueprint $table): void {
            if (Schema::hasIndex('phr_health_logs', self::NEW_UNIQUE)) {
                $table->dropUnique(self::NEW_UNIQUE);
            }
        });
        Schema::table('phr_health_logs', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn('name_reserved');
        });
        Schema::table('phr_health_log_entries', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
