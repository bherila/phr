<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiPackage;

final readonly class EhiIntakeResult
{
    public function __construct(
        public PhrEhiPackage $package,
        /** The package was already preserved and accounted; nothing new was stored. */
        public bool $replayed,
        /** An earlier, incomplete intake of the same package was continued. */
        public bool $resumed,
        public EhiPackageSummary $summary,
        /** Missing or damaged stored objects of existing blobs republished from the source bytes. */
        public int $repaired = 0,
    ) {}
}
