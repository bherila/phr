<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrMedicationFill;
use App\Services\PHR\Fills\MedicationFillDrugResolver;
use App\Services\PHR\Fills\MedicationFillWriter;
use App\Services\PHR\Ndc\NdcNumber;
use App\Services\PHR\Ndc\NdcResolution;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-looks-up fills whose NDC never resolved and resolves the ones the directories now answer, which
 * after phr#283 includes discontinued products that only NLM RxNav's NDC history still knows.
 *
 * Only `unresolved` fills with a valid captured NDC are looked up; `manual`, `ambiguous` and
 * `resolved` fills, rejected fills and withdrawn fills are never touched. A hit goes through the
 * same MedicationFillDrugResolver the web form and the agent API use, and only the drug columns and
 * the NDC status are written: captured values (ndc_as_captured, drug_as_printed, quantities,
 * directions, review status) stay as they are. Like a person's edit of the NDC, the fill's
 * medication link is kept while it still fits the drug. The row is re-read under a lock and skipped
 * if anything changed since it was read, so a concurrent edit is never overwritten.
 *
 * Safe to run repeatedly: a resolved fill is no longer selected. A fill that still does not resolve
 * is looked up again (answers are cached for hours). This command calls openFDA and RxNav, also in
 * a dry run, and waits between fills to stay polite to NLM (20 requests a second allowed). It is
 * deliberately not part of the deploy.
 */
#[Signature('phr:fills:resolve-discontinued {--dry-run : Look the NDCs up and report what would resolve, without writing} {--delay-ms=250 : Wait between fills, in milliseconds} {--max-seconds=0 : Stop starting new lookups after this many seconds (0 = no limit)}')]
#[Description('Resolve unresolved fills from openFDA or, for discontinued products, RxNav NDC history')]
final class PhrFillsResolveDiscontinuedCommand extends BasePhrCommand
{
    /** An outage affects every fill alike, so the run stops once this many lookups could not be answered. */
    private const int GIVE_UP_AFTER_OUTAGES = 5;

    public function handle(MedicationFillDrugResolver $resolver, MedicationFillWriter $writer, AgentClinicalRecordVersion $versions): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $delay = max(0, (int) $this->option('delay-ms'));
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        $startedAt = microtime(true);
        $stopped = false;
        $outage = false;
        $counts = ['scanned' => 0, 'resolved' => 0, 'discontinued' => 0, 'still_unresolved' => 0, 'ambiguous' => 0, 'unavailable' => 0, 'changed_meanwhile' => 0];

        PhrMedicationFill::query()
            ->where('ndc_status', PhrMedicationFill::NDC_UNRESOLVED)
            ->whereNotNull('ndc_as_captured')
            ->whereNull('retracted_at')
            ->where('review_status', '!=', PhrReviewStatus::REJECTED)
            ->chunkById(100, function ($fills) use ($resolver, $writer, $versions, $dryRun, $delay, $maxSeconds, $startedAt, &$stopped, &$outage, &$counts): bool {
                foreach ($fills as $fill) {
                    if ($maxSeconds > 0 && microtime(true) - $startedAt >= $maxSeconds) {
                        $stopped = true;

                        return false;
                    }
                    if (NdcNumber::packageCandidates((string) $fill->ndc_as_captured) === []) {
                        continue;
                    }
                    $counts['scanned']++;
                    $this->handleFill($fill, $resolver, $writer, $versions, $dryRun, $counts);
                    if ($counts['unavailable'] >= self::GIVE_UP_AFTER_OUTAGES) {
                        $outage = true;

                        return false;
                    }
                    if ($delay > 0) {
                        usleep($delay * 1000);
                    }
                }

                return true;
            });

        $verb = $dryRun ? 'would resolve' : 'resolved';
        $this->info(($dryRun ? '[dry run] ' : '')."Looked up {$counts['scanned']} unresolved fill(s); {$verb} {$counts['resolved']} ({$counts['discontinued']} discontinued); "
            ."{$counts['still_unresolved']} still unresolved, {$counts['ambiguous']} ambiguous, {$counts['unavailable']} could not be looked up, {$counts['changed_meanwhile']} changed while running.");
        if ($stopped) {
            $this->warn("Stopped after {$maxSeconds} seconds; run the command again to continue.");
        }
        if ($outage) {
            $this->warn('Stopped early: the directories kept failing to answer.');
        }
        if ($counts['unavailable'] > 0) {
            $this->warn('Some lookups could not be completed; run the command again later to retry them.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function handleFill(PhrMedicationFill $fill, MedicationFillDrugResolver $resolver, MedicationFillWriter $writer, AgentClinicalRecordVersion $versions, bool $dryRun, array &$counts): void
    {
        try {
            // The printed text only keeps the resolver from refusing a miss; a miss is never written.
            $prepared = $resolver->resolve(['ndc' => (string) $fill->ndc_as_captured, 'drug_as_printed' => $fill->drug_as_printed ?? 'unresolved'], $fill, false);
        } catch (ValidationException) {
            // Not resolved, and the fill has no printed drug text to fall back on; nothing to change.
            $counts['still_unresolved']++;

            return;
        }

        $resolution = $prepared['resolution'];
        if ($resolution === null || $resolution->status !== NdcResolution::RESOLVED) {
            $key = match (true) {
                $resolution?->status === NdcResolution::AMBIGUOUS => 'ambiguous',
                $resolution?->reason === NdcResolution::REASON_UNAVAILABLE => 'unavailable',
                default => 'still_unresolved',
            };
            $counts[$key]++;

            return;
        }

        $discontinued = $resolution->chosen()?->discontinued === true;
        if ($dryRun) {
            $counts['resolved']++;
            $counts['discontinued'] += $discontinued ? 1 : 0;

            return;
        }

        // The captured NDC stays exactly as captured.
        $attributes = $prepared['attributes'];
        unset($attributes['ndc_as_captured']);
        $loaded = $versions->for($fill);

        $written = DB::transaction(function () use ($fill, $attributes, $loaded, $writer, $versions): bool {
            $locked = PhrMedicationFill::query()->whereKey($fill->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->ndc_status !== PhrMedicationFill::NDC_UNRESOLVED || ! hash_equals($loaded, $versions->for($locked))) {
                return false;
            }
            $locked->forceFill($attributes)->save();
            $patient = $locked->patient;
            if ($patient !== null) {
                // Like a person's edit of the NDC: the medication link is kept while it still fits the drug.
                $writer->reconcile($patient, $locked);
            }

            return true;
        });

        if ($written) {
            $counts['resolved']++;
            $counts['discontinued'] += $discontinued ? 1 : 0;
        } else {
            $counts['changed_meanwhile']++;
        }
    }
}
