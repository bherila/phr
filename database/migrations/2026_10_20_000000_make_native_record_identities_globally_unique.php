<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A native record identity is now also the record's public id for patients,
 * documents and EOBs, so it must name one record of its type across the whole
 * installation, not only within one patient.
 *
 * Identities are random UUIDs and a restore recreates a record's identity only
 * under the one patient the archive is restored to (an existing patient with the
 * same root identity is the target, two such patients block the restore), so a
 * duplicate should not exist. If one does, the oldest row keeps the identity and
 * every later row is re-keyed before the index is added: no public id has been
 * published before this migration, so nothing outside the database refers to it.
 */
return new class extends Migration
{
    private const string INDEX = 'phr_native_identity_public_uq';

    public function up(): void
    {
        if (! Schema::hasTable('phr_native_record_identities')
            || Schema::hasIndex('phr_native_record_identities', self::INDEX)) {
            return;
        }

        $duplicates = DB::table('phr_native_record_identities')
            ->select(['record_table', 'native_id'])
            ->groupBy(['record_table', 'native_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicates as $duplicate) {
            $later = DB::table('phr_native_record_identities')
                ->where('record_table', $duplicate->record_table)
                ->where('native_id', $duplicate->native_id)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);
            foreach ($later as $id) {
                DB::table('phr_native_record_identities')->where('id', $id)->update(['native_id' => (string) Str::uuid()]);
            }
        }

        Schema::table('phr_native_record_identities', static function (Blueprint $table): void {
            $table->unique(['record_table', 'native_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('phr_native_record_identities')
            && Schema::hasIndex('phr_native_record_identities', self::INDEX)) {
            Schema::table('phr_native_record_identities', static function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
