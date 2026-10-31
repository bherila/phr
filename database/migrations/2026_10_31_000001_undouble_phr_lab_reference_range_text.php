<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores once every `phr_lab_results.reference_range_text` that holds one
 * string written twice ("NEGNEG", "Not DetectedNot Detected").
 *
 * A one-off C-CDA load stored the whole text content of each observationRange,
 * which concatenates its <text> with a <value xsi:type="ST"> that repeats it.
 * The importer now reads one of them (PhrCcdaResultObservations).
 *
 * A value is rewritten only when its two halves are byte-for-byte equal, and
 * never when it is a number: "11" or "2020" is a value, not a repetition. The
 * comparison is in PHP and the update is by id, so neither a regex
 * backreference dialect nor a case-insensitive collation decides it ("Not
 * DetectedNot detected" is left alone). A unit-only text ("mIU/mLmIU/mL") is
 * only stored once, not moved: the rows that hold one already carry the unit in
 * range_unit, and the source printed it as its range.
 *
 * `updated_at` is left alone: this undoes a load error rather than editing the
 * result, and a native backup's record hash covers `updated_at`, so touching it
 * would make a backup taken before this migration conflict with its own rows.
 * Archives taken before it are repaired on restore by
 * PhrNativeLabReferenceRangeCompatibility. Withdrawn rows are included.
 *
 * The rule is frozen here on purpose: the result of a migration must not change
 * when the application's rule does later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_lab_results') || ! Schema::hasColumn('phr_lab_results', 'reference_range_text')) {
            return;
        }

        DB::table('phr_lab_results')
            ->select(['id', 'reference_range_text'])
            ->whereNotNull('reference_range_text')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $single = $this->single($row->reference_range_text);
                    if ($single === null) {
                        continue;
                    }

                    DB::table('phr_lab_results')
                        ->where('id', $row->id)
                        ->update(['reference_range_text' => $single]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally a no-op. The doubled text was a load error, not data
        // anyone wrote, and restoring it would only show it doubled again.
    }

    /**
     * The one copy of a string written twice, or null when it is not one.
     *
     * The load wrote exactly two copies. A string whose half is itself doubled
     * did not come from it, and halving it would let a second run halve it
     * again, so it is left as it is.
     */
    private function single(mixed $text): ?string
    {
        if (! is_string($text) || is_numeric($text)) {
            return null;
        }

        $half = $this->half($text);

        return $half === null || $this->half($half) !== null ? null : $half;
    }

    private function half(string $text): ?string
    {
        $length = strlen($text);
        if ($length < 2 || $length % 2 !== 0) {
            return null;
        }

        $half = substr($text, 0, intdiv($length, 2));

        return $half === substr($text, intdiv($length, 2)) && trim($half) !== '' ? $half : null;
    }
};
