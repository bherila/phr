<?php

namespace App\GenAiProcessor\Services;

use RuntimeException;
use Throwable;

/**
 * A permanent loss of the authorization external work depends on: the owning
 * account can no longer sign in, the source document was deleted, or the
 * patient write grant was revoked.
 *
 * Infrastructure failures (storage or database outages) are deliberately NOT
 * reported with this exception; they stay ordinary throwables so the import
 * remains retryable.
 *
 * `$reasonCode` is the stable, log-safe identifier of which authorization was
 * lost. Diagnostics record it instead of the message, so what reaches a log
 * never depends on how a message is worded - or on a later construction
 * interpolating something into it - and the previous chain is never read.
 */
final class PhrExternalEnqueueUnauthorized extends RuntimeException
{
    public const string OWNER_UNAVAILABLE = 'owner_unavailable';

    public const string SOURCE_DOCUMENT_UNAVAILABLE = 'source_document_unavailable';

    public const string PATIENT_GRANT_UNAVAILABLE = 'patient_grant_unavailable';

    /**
     * @param  self::OWNER_UNAVAILABLE|self::SOURCE_DOCUMENT_UNAVAILABLE|self::PATIENT_GRANT_UNAVAILABLE  $reasonCode
     */
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
