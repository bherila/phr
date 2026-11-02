<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Lab;

use App\Services\PHR\Import\Ehi\Mapping\EhiApplyRefused;

/** A lab proposal apply that was refused. `reason` is a fixed code; the message is structural. */
final class EhiLabApplyRefused extends EhiApplyRefused
{
    public const string DECISION_NOT_APPLICABLE = 'decision_not_applicable';

    public const string WRONG_SCOPE = 'wrong_scope';

    public const string MALFORMED_PROPOSAL = 'malformed_proposal';

    /** The import identity was deleted or retracted; it is never resurrected. */
    public const string IDENTITY_WITHDRAWN = 'identity_withdrawn';

    public const string IDENTITY_EXISTS = 'identity_exists';

    public const string TARGET_NOT_FOUND = 'target_not_found';

    public const string TARGET_WITHDRAWN = 'target_withdrawn';

    public const string TARGET_CHANGED = 'target_changed';
}
