<?php

namespace App\Services\PHR\Encounters;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * PhrEncounterLinks refused a link (409), with why, so a caller that links several records
 * can report each one's refusal instead of failing them all (#449). The agent routes answer
 * it as the plain conflict it extends.
 */
final class EncounterLinkRefused extends ConflictHttpException
{
    /** The visit is rejected: it takes no new links. */
    public const string VISIT_REJECTED = 'visit_rejected';

    /** The record is rejected. */
    public const string REJECTED = 'rejected';

    /** The record is already on another standing visit, which must give it up first. */
    public const string LINKED_ELSEWHERE = 'linked_elsewhere';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $linkedVisitId = null,
    ) {
        parent::__construct($message);
    }
}
