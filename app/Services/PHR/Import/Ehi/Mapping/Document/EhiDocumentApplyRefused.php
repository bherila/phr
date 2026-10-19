<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Services\PHR\Import\Ehi\Mapping\EhiApplyRefused;

/** A document proposal apply that was refused; nothing was written. `reason` is a fixed code; the message is structural. */
final class EhiDocumentApplyRefused extends EhiApplyRefused
{
    /** The decision does not apply to this proposal kind. */
    public const string DECISION_NOT_APPLICABLE = 'decision_not_applicable';

    /** The proposal belongs to another patient, mapping, or source. */
    public const string WRONG_SCOPE = 'wrong_scope';

    /** The proposal carries no usable proposed attributes. */
    public const string MALFORMED_PROPOSAL = 'malformed_proposal';

    /** The import identity was deleted or retracted; it is never resurrected. */
    public const string IDENTITY_WITHDRAWN = 'identity_withdrawn';

    /** A live document already holds the import identity; the preview is stale. */
    public const string IDENTITY_EXISTS = 'identity_exists';

    /** The target or link document is missing or not this patient's document. */
    public const string TARGET_NOT_FOUND = 'target_not_found';

    /** The target or link document was deleted or retracted. */
    public const string TARGET_WITHDRAWN = 'target_withdrawn';

    /** The target changed since the proposal was previewed. */
    public const string TARGET_CHANGED = 'target_changed';

    /** The linked EHI member's preserved bytes are missing or no longer match the manifest. */
    public const string EVIDENCE_UNAVAILABLE = 'evidence_unavailable';

    /** The document file could not be stored. */
    public const string STORAGE_FAILED = 'storage_failed';
}
