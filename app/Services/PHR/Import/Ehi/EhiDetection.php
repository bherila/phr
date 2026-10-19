<?php

namespace App\Services\PHR\Import\Ehi;

/** Whether a member list looks like an EHI Tables package, with fixed reason codes. */
final readonly class EhiDetection
{
    public function __construct(
        public bool $isEhi,
        /** @var list<string> EhiPackageDetector::SIGNAL_* codes that held */
        public array $reasons,
        public int $tableCount,
        public int $dictionaryCount,
    ) {}
}
