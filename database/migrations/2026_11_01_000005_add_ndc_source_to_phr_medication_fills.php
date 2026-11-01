<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which directory resolved a fill's drug: `openfda`, or `rxnav` for a discontinued
 * product that openFDA no longer lists. Every fill resolved before this column existed was
 * resolved by openFDA, so those rows are backfilled `openfda`; unresolved, ambiguous and manual
 * fills have no source. Re-running changes nothing (only rows with no source are touched).
 * `updated_at` is left alone. down() drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_medication_fills')) {
            return;
        }

        if (! Schema::hasColumn('phr_medication_fills', 'ndc_source')) {
            Schema::table('phr_medication_fills', function (Blueprint $table): void {
                $table->string('ndc_source', 16)->nullable()->after('ndc_status');
            });
        }

        DB::table('phr_medication_fills')
            ->where('ndc_status', 'resolved')
            ->whereNull('ndc_source')
            ->update(['ndc_source' => 'openfda']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('phr_medication_fills', 'ndc_source')) {
            Schema::table('phr_medication_fills', function (Blueprint $table): void {
                $table->dropColumn('ndc_source');
            });
        }
    }
};
