<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Immunization;

use App\Services\PHR\Import\Ehi\Mapping\EhiApplyRefused;

/** An immunization proposal apply that was refused. `reason` is a fixed code; the message is structural. */
final class EhiImmunizationApplyRefused extends EhiApplyRefused
{
    /** The decision does not apply to this proposal kind. */
    public const string DECISION_NOT_APPLICABLE = 'decision_not_applicable';

    /** The proposal belongs to another patient, mapping, or source. */
    public const string WRONG_SCOPE = 'wrong_scope';

    /** The proposal carries no usable proposed attributes. */
    public const string MALFORMED_PROPOSAL = 'malformed_proposal';

    /** The import identity was deleted or retracted; it is never resurrected. */
    public const string IDENTITY_WITHDRAWN = 'identity_withdrawn';

    /** A live record already holds the import identity; the preview is stale. */
    public const string IDENTITY_EXISTS = 'identity_exists';

    /** The target or link record is missing or not this patient's immunization. */
    public const string TARGET_NOT_FOUND = 'target_not_found';

    /** The target or link record was deleted or retracted. */
    public const string TARGET_WITHDRAWN = 'target_withdrawn';

    /** The target changed since the proposal was previewed. */
    public const string TARGET_CHANGED = 'target_changed';
}
