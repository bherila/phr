<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills the ten-digit and eleven-digit NDC on fills saved before the owner's
 * hard-coded 11-to-10 rule, which left `ndc_10` empty for an eleven-digit NDC
 * that was unresolved or ambiguous.
 *
 * Only rows with a null `ndc_10` and an `ndc_as_captured` of eleven digits
 * (5-4-2, hyphens optional) are touched. Split 5-4-2 and take the first match:
 * drop a leading 0 from the first segment, else the middle segment, else the
 * last. A row where no segment starts with 0 gets nothing. Rows are not
 * re-resolved: an `ambiguous` fill stays ambiguous until it is edited.
 * Applying it twice changes nothing (a filled `ndc_10` is never selected again);
 * down() is a no-op. `updated_at` is left alone. The rule is frozen here on
 * purpose, mirroring App\Services\PHR\Ndc\NdcNumber::toTenDigit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_medication_fills')
            || ! Schema::hasColumn('phr_medication_fills', 'ndc_10')
            || ! Schema::hasColumn('phr_medication_fills', 'ndc_11')
            || ! Schema::hasColumn('phr_medication_fills', 'ndc_as_captured')) {
            return;
        }

        DB::table('phr_medication_fills')
            ->select(['id', 'ndc_as_captured', 'ndc_11'])
            ->whereNull('ndc_10')
            ->whereNotNull('ndc_as_captured')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $digits = $this->elevenDigits((string) $row->ndc_as_captured);
                    $ten = $digits === null ? null : $this->toTenDigit($digits);
                    if ($digits === null || $ten === null) {
                        continue;
                    }

                    DB::table('phr_medication_fills')
                        ->where('id', $row->id)
                        ->whereNull('ndc_10')
                        ->update(['ndc_10' => $ten, 'ndc_11' => $row->ndc_11 ?? $digits]);
                }
            });
    }

    public function down(): void
    {
        // The backfilled values cannot be told from ones written by the application.
    }

    private function elevenDigits(string $captured): ?string
    {
        $text = (string) preg_replace('/^\s*NDC\s*[:#]?\s*/i', '', $captured);
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);
        $text = (string) preg_replace('/\s+/', '', $text);
        if (preg_match('/\A[0-9]{5}-[0-9]{4}-[0-9]{2}\z/', $text) === 1) {
            return str_replace('-', '', $text);
        }

        return preg_match('/\A[0-9]{11}\z/', $text) === 1 ? $text : null;
    }

    private function toTenDigit(string $digits): ?string
    {
        $segments = [substr($digits, 0, 5), substr($digits, 5, 4), substr($digits, 9, 2)];
        foreach ([0, 1, 2] as $index) {
            if (str_starts_with($segments[$index], '0')) {
                $segments[$index] = substr($segments[$index], 1);

                return implode('-', $segments);
            }
        }

        return null;
    }
};
