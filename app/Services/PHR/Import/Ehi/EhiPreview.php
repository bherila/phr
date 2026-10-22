<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiPackage;

/**
 * Outcome of previewing one package. Every key is a mapping name, a fixed kind,
 * status, or finding code, so it is safe to print.
 */
final readonly class EhiPreview
{
    public function __construct(
        public PhrEhiPackage $package,
        public EhiProjectionResult $projection,
        /** @var array<string, array<string, int>> mapping => kind => drafts produced by this preview */
        public array $counts,
        /** @var array<string, int> status => proposals of this package after the preview */
        public array $statuses,
        /** @var array<string, int> finding code => count raised by the mappings */
        public array $findings,
        /** sha256 over the sorted preview digests of every non-superseded proposal of the package. */
        public string $digest,
    ) {}
}
