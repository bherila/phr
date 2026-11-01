<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether each lab reference bound includes its own value (phr#259): a structured
 * source can say `<high inclusive="true" value="5"/>`, which reads as "at most 5",
 * not "below 5". Null means the source did not say, which is every existing row:
 * nothing here backfills, and the lab views keep showing those rows as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_lab_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('phr_lab_results', 'range_min_inclusive')) {
                $table->boolean('range_min_inclusive')->nullable()->after('range_max');
            }
            if (! Schema::hasColumn('phr_lab_results', 'range_max_inclusive')) {
                $table->boolean('range_max_inclusive')->nullable()->after('range_min_inclusive');
            }
        });
    }

    public function down(): void
    {
        Schema::table('phr_lab_results', function (Blueprint $table): void {
            $table->dropColumn(['range_min_inclusive', 'range_max_inclusive']);
        });
    }
};
