<?php

namespace App\Console\Commands;

use App\Support\Storage\PhrStorageMap;
use App\Support\Storage\PrunePlan;
use App\Support\Storage\StoragePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Quarantines stored objects that no database row references.
 *
 * Reports by default; --apply is required to move anything. Objects go to a dated
 * quarantine prefix, and are only really deleted by a later --purge-quarantine run once
 * the holding period has elapsed.
 *
 * Run this AFTER a deploy and AFTER a verified backup, never before. The backup is what
 * makes the quarantine step recoverable if the reference map turns out to be wrong.
 *
 * Complements phr:dicom:gc rather than replacing it: that command repairs DICOM upload
 * *state* (failing stale pending uploads, reclaiming superseded derived volumes), which
 * is domain logic this command knows nothing about.
 */
class StoragePrune extends Command
{
    protected $signature = 'storage:prune
        {--apply : Move orphans to quarantine. Without this, nothing is touched}
        {--min-age-hours=24 : Leave objects newer than this alone}
        {--max-orphan-ratio=0.25 : Abort if orphans exceed this share of objects scanned}
        {--force : Proceed despite the safety ratio. Think first}
        {--purge-quarantine= : Instead of sweeping, delete quarantine batches older than N days}';

    protected $description = 'Quarantine stored objects that no database row references.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $references = PhrStorageMap::references();
        $stamp = Carbon::now()->format('Y-m-d-His');

        if (! $apply) {
            $this->warn('DRY RUN — nothing will be moved. Pass --apply to quarantine.');
        }

        $exit = self::SUCCESS;

        foreach (PhrStorageMap::disks() as $diskName => $roots) {
            $this->newLine();
            $this->info("disk: {$diskName}");

            $pruner = new StoragePruner(
                Storage::disk($diskName),
                $references,
                $roots,
                (int) $this->option('min-age-hours'),
                (float) $this->option('max-orphan-ratio'),
            );

            if (($days = $this->option('purge-quarantine')) !== null) {
                $result = $pruner->purgeQuarantine((int) $days, $apply);
                $this->line(sprintf(
                    '  quarantine: %d batch(es), %d file(s) %s',
                    count($result['batches']),
                    $result['files'],
                    $apply ? 'deleted' : 'eligible',
                ));

                continue;
            }

            $plan = $pruner->plan();
            $this->report($plan);

            if ($plan->orphanCount() === 0) {
                continue;
            }

            if ($plan->exceedsSafetyThreshold() && ! $this->option('force')) {
                $this->error(sprintf(
                    '  ABORTED: %.1f%% of scanned objects look orphaned (limit %.1f%%).',
                    $plan->ratio() * 100,
                    $plan->maxOrphanRatio * 100,
                ));
                $this->line('  This usually means a column is missing from PhrStorageMap, not that');
                $this->line('  the storage is full of garbage. Check the map before using --force.');
                $exit = self::FAILURE;

                continue;
            }

            if (! $apply) {
                continue;
            }

            $result = $pruner->quarantine($plan, $stamp);
            $this->line("  quarantined {$result['moved']} object(s) into "
                .StoragePruner::QUARANTINE_ROOT."/{$stamp}/");

            if ($result['failed'] !== []) {
                $this->error('  failed to move '.count($result['failed']).' object(s)');
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    private function report(PrunePlan $plan): void
    {
        $this->line(sprintf(
            '  scanned %d, unreferenced %d (%.1f%%), too new to touch %d',
            $plan->scanned,
            $plan->orphanCount(),
            $plan->ratio() * 100,
            $plan->skippedTooNew,
        ));

        foreach (array_slice($plan->orphans, 0, 20) as $key) {
            $this->line("    orphan: {$key}");
        }

        if ($plan->orphanCount() > 20) {
            $this->line('    ... and '.($plan->orphanCount() - 20).' more');
        }
    }
}
