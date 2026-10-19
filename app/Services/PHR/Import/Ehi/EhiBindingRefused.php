<?php

namespace App\Services\PHR\Import\Ehi;

use RuntimeException;

/** A binding request that was refused. `reason` is a fixed code; the message is structural. */
final class EhiBindingRefused extends RuntimeException
{
    /** The package is not yet preserved and accounted. */
    public const string NOT_READY = 'not_ready';

    /** The package has no detected source patient key to bind. */
    public const string NO_SOURCE_PATIENT_KEY = 'no_source_patient_key';

    /** The chosen source does not exist for this patient. */
    public const string SOURCE_NOT_FOUND = 'source_not_found';

    /** The chosen source declares a different source patient key. */
    public const string SOURCE_KEY_MISMATCH = 'source_key_mismatch';

    /** The package is already bound to a different source. */
    public const string ALREADY_BOUND = 'already_bound';

    /** A source with that label already exists for this patient. */
    public const string LABEL_TAKEN = 'label_taken';

    /** The label is empty, too long, or contains control characters. */
    public const string LABEL_INVALID = 'label_invalid';

    /** Exactly one of an existing source or a new source label is required. */
    public const string CHOICE_REQUIRED = 'choice_required';

    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct(sprintf('EHI binding refused (%s): %s.', $reason, $detail));
    }
}
