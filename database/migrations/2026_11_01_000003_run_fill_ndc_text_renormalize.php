<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One-time run of `phr:fills:renormalize-ndc-text` (phr#277) on deploy, approved by
 * the owner on 2026-11-01. Fills saved before #277 keep openFDA's raw unit notation
 * ("5mL", "[iU]", "meq") in `strengths` and `package_description`, while new fills
 * get the normalized form; the medication linker groups fills by strength, so the
 * two must match.
 *
 * The command rewrites only those two columns from each fill's untouched
 * `fda_snapshot`, re-reads each row under a lock, leaves `updated_at` and review
 * status alone, and is idempotent. On a fresh database there is nothing to do.
 * It calls the live command rather than freezing the rule here, because the rule
 * is the command's whole job; a later re-run would only re-apply the current rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_medication_fills')
            || ! Schema::hasColumn('phr_medication_fills', 'fda_snapshot')) {
            return;
        }

        Artisan::call('phr:fills:renormalize-ndc-text');
        $summary = trim(Artisan::output());
        Log::info('phr: fill NDC text renormalized on deploy', ['summary' => $summary]);
        // Shown in the deploy log beside the migration name.
        if (defined('STDOUT')) {
            fwrite(STDOUT, $summary.PHP_EOL);
        }
    }

    public function down(): void
    {
        // Normalization is not reversed; the raw text remains in fda_snapshot.
    }
};
