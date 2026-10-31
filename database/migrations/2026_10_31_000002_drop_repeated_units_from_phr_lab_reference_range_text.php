<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores once a range unit repeated at the end of
 * `phr_lab_results.reference_range_text` ("4.3 - 5.6 PERCENTPERCENTPERCENT").
 *
 * The same one-off C-CDA load as the previous migration took observationRange's
 * whole text content. For an IVL_PQ in a non-UCUM unit, that appends the unit
 * once more for each bound carrying it in translation/originalText. The row's
 * range_unit holds that unit, so the repair strips trailing copies of exactly
 * range_unit until one is left.
 *
 * Only range_unit counts, compared in PHP byte for byte and updated by id, so a
 * collation never decides it. A numeric unit is skipped: with UCUM's unitless
 * "1", "<11" is a range, not a repeated unit. Applying the rule to its own
 * output changes nothing, so up() is idempotent; down() is a no-op.
 * `updated_at` is left alone for the reason the previous migration gives, and
 * PhrNativeLabReferenceRangeCompatibility applies the same rule on restore.
 *
 * The rule is frozen here on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_lab_results')
            || ! Schema::hasColumn('phr_lab_results', 'reference_range_text')
            || ! Schema::hasColumn('phr_lab_results', 'range_unit')) {
            return;
        }

        DB::table('phr_lab_results')
            ->select(['id', 'reference_range_text', 'range_unit'])
            ->whereNotNull('reference_range_text')
            ->whereNotNull('range_unit')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $repaired = $this->withoutRepeatedUnit($row->reference_range_text, $row->range_unit);
                    if ($repaired === null) {
                        continue;
                    }

                    DB::table('phr_lab_results')
                        ->where('id', $row->id)
                        ->update(['reference_range_text' => $repaired]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally a no-op. The repeated unit was a load error, not data
        // anyone wrote, and restoring it would only show it repeated again.
    }

    private function withoutRepeatedUnit(mixed $text, mixed $unit): ?string
    {
        if (! is_string($text) || ! is_string($unit) || trim($unit) === '' || is_numeric($unit)) {
            return null;
        }

        $repaired = $text;
        while (str_ends_with($repaired, $unit.$unit)) {
            $repaired = substr($repaired, 0, -strlen($unit));
        }

        return $repaired === $text ? null : $repaired;
    }
};
