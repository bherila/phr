<?php

use App\Models\PhrMedicationFill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One-time run of `phr:fills:resolve-discontinued` on deploy, approved by the owner on 2026-11-01.
 * Fills saved with an NDC that openFDA did not list (discontinued products) are looked up in NLM
 * RxNav's NDC history and resolved; only the drug columns and the NDC status change.
 *
 * It must never fail a deploy because NLM is slow or down: any exception is logged as a warning
 * and the migration still succeeds, leaving the fills unresolved for the command to pick up later.
 * The run is capped at 60 seconds (about 240 fills at the command's polite pace); whatever is left
 * is resolved by running the command by hand. A fill is resolved at most once, so re-running changes
 * nothing. A fresh or empty database, or one with no unresolved fill, makes no external call.
 */
return new class extends Migration
{
    private const int MAX_SECONDS = 60;

    public function up(): void
    {
        try {
            if (! Schema::hasTable('phr_medication_fills')
                || ! Schema::hasColumn('phr_medication_fills', 'ndc_source')
                || ! PhrMedicationFill::query()->where('ndc_status', PhrMedicationFill::NDC_UNRESOLVED)->whereNotNull('ndc_as_captured')->exists()) {
                return;
            }

            Artisan::call('phr:fills:resolve-discontinued', ['--max-seconds' => self::MAX_SECONDS]);
            $summary = trim(Artisan::output());
            Log::info('phr: unresolved fills re-resolved on deploy', ['summary' => $summary]);
            // Shown in the deploy log beside the migration name.
            if (defined('STDOUT')) {
                fwrite(STDOUT, $summary.PHP_EOL);
            }
        } catch (Throwable $exception) {
            Log::warning('phr: re-resolving unresolved fills on deploy was skipped', ['error' => $exception->getMessage()]);
        }
    }

    public function down(): void
    {
        // A resolved drug is not un-resolved; the raw responses remain in fda_snapshot.
    }
};
