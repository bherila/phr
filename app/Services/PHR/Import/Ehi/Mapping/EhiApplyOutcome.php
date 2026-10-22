<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

/**
 * The clinical record an applied proposal produced or linked, and how.
 */
final readonly class EhiApplyOutcome
{
    /**
     * @param  string  $recordTable  e.g. "phr_immunizations"
     * @param  string  $linkKind  a PhrEhiRecordLink::KIND_* constant
     */
    public function __construct(
        public string $recordTable,
        public int $recordId,
        public string $linkKind,
    ) {}
}
