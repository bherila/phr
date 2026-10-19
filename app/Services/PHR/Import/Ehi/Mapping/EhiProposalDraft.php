<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

/**
 * One proposal as a mapping produced it, before it is persisted.
 */
final readonly class EhiProposalDraft
{
    /**
     * @param  string  $subjectKey  stable within (package, mapping): usually the root entity key digest
     * @param  string  $kind  a PhrEhiProposal::KIND_* constant
     * @param  list<int>  $revisionIds  every revision the proposal relies on (root and joined rows)
     * @param  array<string, mixed>|null  $proposed  target attributes after apply
     * @param  array<string, mixed>|null  $before  current target attributes, for updates
     * @param  list<int>  $candidateIds  possible-duplicate records a reviewer may link
     */
    public function __construct(
        public string $subjectKey,
        public string $kind,
        public array $revisionIds,
        public ?array $proposed = null,
        public ?string $targetTable = null,
        public ?int $targetId = null,
        public ?array $before = null,
        public array $candidateIds = [],
    ) {}
}
