<?php

namespace App\Support\Storage;

/**
 * The result of a prune sweep, computed before anything is touched.
 */
class PrunePlan
{
    /**
     * @param  list<string>  $orphans  keys referenced by nothing and old enough to reap
     */
    public function __construct(
        public readonly array $orphans,
        public readonly int $scanned,
        public readonly int $skippedTooNew,
        public readonly float $maxOrphanRatio,
    ) {}

    public function orphanCount(): int
    {
        return count($this->orphans);
    }

    public function ratio(): float
    {
        return $this->scanned === 0 ? 0.0 : $this->orphanCount() / $this->scanned;
    }

    /**
     * Whether the sweep found an implausible share of the storage to be garbage.
     *
     * This is the check that catches a missing entry in BlobReferences: if a column stops
     * being consulted, everything it protected suddenly looks unreferenced, and the ratio
     * spikes. Refusing here turns "silently deleted a table's worth of files" into "the
     * command stopped and told you".
     */
    public function exceedsSafetyThreshold(): bool
    {
        return $this->scanned > 0 && $this->ratio() > $this->maxOrphanRatio;
    }
}
