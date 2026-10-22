<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrEhiProposal;

/**
 * A versioned, deterministic contract from source tables to clinical proposals.
 *
 * A mapping declares the tables it needs and the primary key it expects for each.
 * Projection verifies those keys against the package's own dictionary before any
 * entity is created; a mismatch is a finding, never a guess. Bumping version()
 * changes proposal identity, so earlier decisions stay attributable to the
 * mapping revision that produced them.
 */
interface EhiMapping
{
    /** Stable machine name, e.g. "immunization". */
    public function name(): string;

    /** At most 32 characters, e.g. "ehi-imm-1". */
    public function version(): string;

    /**
     * Source tables this mapping projects, with the full primary key expected
     * for each (column order significant).
     *
     * @return array<string, list<string>>
     */
    public function expectedKeys(): array;

    /**
     * Build proposals for one bound package. Must be deterministic: the same
     * revisions and target state always yield the same drafts.
     *
     * @return iterable<EhiProposalDraft>
     */
    public function propose(EhiMappingContext $context): iterable;

    /**
     * Apply an accepted (or approved-update) proposal inside the caller's patient
     * write guard and transaction, re-validating the target. Returns the record
     * it created or changed, or the record it links to.
     */
    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome;
}
