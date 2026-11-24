<?php

namespace App\Services\PHR\Encounters;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The visit or the record of a link is not the patient's, or no longer stands (404). It
 * names which of the two, so a batch can refuse one missing record and go on (#449). The
 * agent routes answer it as the plain not-found it extends.
 */
final class EncounterLinkTargetMissing extends NotFoundHttpException
{
    public const string VISIT = 'visit';

    public const string RECORD = 'record';

    public function __construct(public readonly string $target, string $message)
    {
        parent::__construct($message);
    }
}
