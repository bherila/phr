<?php

namespace App\Services\PHR\Import;

use RuntimeException;

/** A single structured file was not imported; carries a fixed reason code. Nothing was written. */
final class PhrStructuredImportRefused extends RuntimeException
{
    /** Neither a C-CDA document nor a FHIR bundle. */
    public const string UNSUPPORTED_FORMAT = 'unsupported_format';

    /** Its records name more than one person. */
    public const string MULTIPLE_PATIENTS = 'multiple_patients';

    /** Recognized, but an entry could not be imported; the whole file was rolled back. */
    public const string UNREADABLE = 'unreadable';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Structured import refused: {$reason}.");
    }
}
