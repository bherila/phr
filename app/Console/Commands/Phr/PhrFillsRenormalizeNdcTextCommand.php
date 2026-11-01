<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrMedicationFill;
use App\Services\PHR\Ndc\NdcCandidate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Re-derives the display text of existing fills (strengths, package description)
 * from the raw openFDA product kept in fda_snapshot, using the current NdcCasing
 * rules. Safe to run repeatedly: a fill whose text is already current is skipped.
 */
#[Signature('phr:fills:renormalize-ndc-text {--dry-run : Report what would change without writing}')]
#[Description('Re-derive fill strengths and package descriptions from fda_snapshot with the current unit notation rules')]
final class PhrFillsRenormalizeNdcTextCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $scanned = 0;
        $changed = 0;

        PhrMedicationFill::query()->withTrashed()->whereNotNull('fda_snapshot')->chunkById(200, function ($fills) use ($dryRun, &$scanned, &$changed): void {
            foreach ($fills as $fill) {
                $scanned++;
                $updates = $this->updatesFor($fill);
                if ($updates === []) {
                    continue;
                }
                $changed++;
                if (! $dryRun) {
                    // Deliberate bypass: this is a display normalization of text derived from
                    // fda_snapshot, not a clinical edit. Only these two columns are written;
                    // updated_at and review status stay as they were (saveQuietly skips model
                    // events, and timestamps are switched off for this save). The record version
                    // is a hash of the row's attributes (AgentClinicalRecordVersion), so it
                    // follows the new text; there is no stored version column to preserve.
                    $fill->forceFill($updates);
                    $fill->timestamps = false;
                    $fill->saveQuietly();
                }
            }
            $this->line("Scanned {$scanned} fill(s), ".($dryRun ? 'would change' : 'changed')." {$changed}...");
        });

        $this->info(($dryRun ? '[dry run] ' : '')."Scanned {$scanned} fill(s); ".($dryRun ? 'would update' : 'updated')." {$changed}.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function updatesFor(PhrMedicationFill $fill): array
    {
        $snapshot = $fill->fda_snapshot;
        if (! is_array($snapshot)) {
            return [];
        }
        $package = null;
        foreach ((array) ($snapshot['packaging'] ?? []) as $candidate) {
            if (is_array($candidate) && strcasecmp((string) ($candidate['package_ndc'] ?? ''), (string) $fill->ndc_10) === 0) {
                $package = $candidate;
                break;
            }
        }

        $derived = NdcCandidate::fromProduct($snapshot, $package ?? ['package_ndc' => (string) $fill->ndc_10], $fill->fda_fetched_at?->toIso8601String() ?? '')->drug;
        $updates = [];
        if ($derived['strengths'] !== [] && $derived['strengths'] !== ($fill->strengths ?? [])) {
            $updates['strengths'] = $derived['strengths'];
        }
        // Without a matching package in the snapshot there is nothing to derive the description from.
        if ($package !== null && $derived['package_description'] !== null && $derived['package_description'] !== $fill->package_description) {
            $updates['package_description'] = $derived['package_description'];
        }

        return $updates;
    }
}
