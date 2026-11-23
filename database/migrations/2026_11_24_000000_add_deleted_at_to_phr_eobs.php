<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An EOB a person or an agent deletes is soft-deleted (#444), like every other
 * record a client mirrors: it leaves every read and changes.list reports it as a
 * tombstone, and its lines, correction history and links stay stored but stop
 * counting (see PhrEob).
 */
return new class extends Migration
{
    private const string INDEX = 'phr_eobs_patient_deleted_idx';

    public function up(): void
    {
        if (! Schema::hasColumn('phr_eobs', 'deleted_at')) {
            Schema::table('phr_eobs', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
        if (! Schema::hasIndex('phr_eobs', self::INDEX)) {
            Schema::table('phr_eobs', function (Blueprint $table): void {
                $table->index(['patient_id', 'deleted_at'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        // Dropping the column would bring every deleted claim back into reads.
        if (Schema::hasColumn('phr_eobs', 'deleted_at') && DB::table('phr_eobs')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Refusing to drop phr_eobs.deleted_at: deleted EOBs would become visible again.');
        }
        Schema::table('phr_eobs', function (Blueprint $table): void {
            if (Schema::hasIndex('phr_eobs', self::INDEX)) {
                $table->dropIndex(self::INDEX);
            }
            if (Schema::hasColumn('phr_eobs', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
