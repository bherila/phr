<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives agent-visible records a lifecycle beyond "present or gone".
 *
 * Two different facts need recording, and collapsing them loses information a
 * synchronizing client needs:
 *
 * - `deleted_at` -- a person removed the record in the browser. Until now this
 *   was a hard delete, so it was invisible to any integration mirroring the
 *   patient: the row simply stopped appearing and no client could tell deletion
 *   from a filter change. An agent mirror diverged permanently. Every day this
 *   is not recorded, deletions are lost irrecoverably, which is why it lands
 *   ahead of the API that exposes it.
 * - `retracted_at` -- the integration that wrote the record withdrew it. That
 *   is deliberately not `review_status = rejected`, which means a human refused
 *   the content, and not `deleted_at`, which is a person's decision rather than
 *   the source's.
 *
 * `lifecycle` is derived from these two rather than stored, so the three states
 * cannot disagree with the timestamps that produce them.
 *
 * `phr_documents` already soft-deletes and only needs the retraction column.
 */
return new class extends Migration
{
    /** Agent-writable tables whose records a client can be expected to mirror. */
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

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes();
                }
                if (! Schema::hasColumn($tableName, 'retracted_at')) {
                    $table->timestamp('retracted_at')->nullable()->after('updated_at');
                }
            });

            // Reads filter the live set on every list, export, and resolve, so
            // the lifecycle columns are indexed with the patient they scope to.
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->index(['patient_id', 'deleted_at', 'retracted_at'], $this->indexName($tableName));
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($this->indexName($tableName));
                if (Schema::hasColumn($tableName, 'retracted_at')) {
                    $table->dropColumn('retracted_at');
                }
                // phr_documents soft-deleted before this migration; its column is
                // not ours to drop.
                if ($tableName !== 'phr_documents' && Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }
    }

    /** Index names are capped at 64 characters, and these table names are long. */
    private function indexName(string $tableName): string
    {
        return substr($tableName, 0, 40).'_lifecycle_idx';
    }
};
