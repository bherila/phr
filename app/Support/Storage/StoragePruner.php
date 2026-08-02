<?php

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reclaims stored objects that no database row references.
 *
 * Quarantines rather than deletes. Objects are moved beneath QUARANTINE_ROOT and only
 * removed later, by an explicit purge, once the holding period has passed. That ordering
 * is what makes a mistake recoverable: an over-eager sweep is a `mv` away from being
 * undone instead of a restore-from-backup away.
 *
 * Quarantine matters more, not less, if this storage ever moves back to an object store —
 * web1's hourly host snapshots would no longer be underneath it, leaving the holding
 * period as the only safety net.
 */
class StoragePruner
{
    public const QUARANTINE_ROOT = '_quarantine';

    /**
     * @param  list<string>  $roots  key prefixes to sweep; nothing outside these is considered
     */
    public function __construct(
        private readonly Filesystem $disk,
        private readonly BlobReferences $references,
        private readonly array $roots,
        private readonly int $minAgeHours = 24,
        private readonly float $maxOrphanRatio = 0.25,
    ) {}

    /**
     * Work out what would be reclaimed, touching nothing.
     */
    public function plan(): PrunePlan
    {
        $keys = $this->references->referencedKeys();
        $prefixes = $this->references->referencedPrefixes();
        $cutoff = Carbon::now()->subHours($this->minAgeHours);

        $orphans = [];
        $scanned = 0;
        $skippedTooNew = 0;

        foreach ($this->roots as $root) {
            foreach ($this->listFiles($root) as $key) {
                // Never re-examine quarantined objects: they are unreferenced by
                // definition, so without this they would be quarantined forever and
                // would drag the safety ratio upward on every subsequent run.
                if (str_starts_with($key, self::QUARANTINE_ROOT.'/')) {
                    continue;
                }

                $scanned++;

                if (BlobReferences::covers($key, $keys, $prefixes)) {
                    continue;
                }

                // An object can exist before the row that references it: a browser
                // upload lands in storage first, and the row is written when the upload
                // is registered. Reaping inside that window destroys a live upload, so
                // anything recently written is left alone.
                if (! $this->olderThan($key, $cutoff)) {
                    $skippedTooNew++;

                    continue;
                }

                $orphans[] = $key;
            }
        }

        return new PrunePlan($orphans, $scanned, $skippedTooNew, $this->maxOrphanRatio);
    }

    /**
     * Move planned orphans into the dated quarantine area.
     *
     * @return array{moved: int, failed: list<string>}
     */
    public function quarantine(PrunePlan $plan, string $stamp): array
    {
        $moved = 0;
        $failed = [];

        foreach ($plan->orphans as $key) {
            $destination = self::QUARANTINE_ROOT.'/'.$stamp.'/'.$key;

            try {
                if ($this->disk->move($key, $destination)) {
                    $moved++;
                } else {
                    $failed[] = $key;
                }
            } catch (Throwable) {
                $failed[] = $key;
            }
        }

        return ['moved' => $moved, 'failed' => $failed];
    }

    /**
     * Delete quarantined batches whose holding period has elapsed.
     *
     * Batch directories are named with the sweep's date, so expiry is decided from the
     * name rather than from per-object mtimes, which the move itself would have reset.
     *
     * @return array{batches: list<string>, files: int}
     */
    public function purgeQuarantine(int $holdDays, bool $apply): array
    {
        $cutoff = Carbon::now()->subDays($holdDays);
        $purged = [];
        $files = 0;

        foreach ($this->disk->directories(self::QUARANTINE_ROOT) as $batch) {
            $stamp = basename($batch);

            try {
                $batchDate = Carbon::createFromFormat('Y-m-d-His', $stamp);
            } catch (Throwable) {
                continue;
            }

            // An unparsable stamp already threw above and was skipped, so the only
            // question left is whether the holding period has elapsed.
            if ($batchDate->greaterThan($cutoff)) {
                continue;
            }

            $files += count($this->listFiles($batch));
            $purged[] = $stamp;

            if ($apply) {
                $this->disk->deleteDirectory($batch);
            }
        }

        return ['batches' => $purged, 'files' => $files];
    }

    /** @return list<string> */
    private function listFiles(string $root): array
    {
        try {
            return $this->disk->allFiles($root);
        } catch (Throwable) {
            return [];
        }
    }

    private function olderThan(string $key, Carbon $cutoff): bool
    {
        try {
            $modified = $this->disk->lastModified($key);
        } catch (Throwable) {
            // Unknown age is treated as too new. Skipping an object costs disk space;
            // reaping one that is still in flight costs the file.
            return false;
        }

        return Carbon::createFromTimestamp($modified)->lessThan($cutoff);
    }
}
