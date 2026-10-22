<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrPatient;
use Illuminate\Support\Carbon;

/**
 * What a reviewer decided, passed to EhiMapping::apply() inside the patient lock.
 */
final readonly class EhiApplyContext
{
    /**
     * @param  string  $decision  PhrEhiProposal::DECISION_ACCEPT, DECISION_APPROVE_UPDATE, or DECISION_LINK
     * @param  int|null  $linkTargetId  the existing record chosen for DECISION_LINK
     * @param  EhiPublishedObjects  $published  every storage object apply() writes must be added here
     */
    public function __construct(
        public PhrPatient $patient,
        public int $actorUserId,
        public string $decision,
        public ?int $linkTargetId,
        public Carbon $now,
        public EhiPublishedObjects $published = new EhiPublishedObjects,
    ) {}
}
