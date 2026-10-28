<?php

namespace App\Services\PHR\Import\Ehi;

use RuntimeException;

/**
 * A browser upload step was refused. Carries a fixed reason code only.
 */
final class EhiUploadRefused extends RuntimeException
{
    /** The patient is archived; nothing new is imported into it. */
    public const string PATIENT_ARCHIVED = 'patient_archived';

    /** The declared size is larger than any package within the EHI limits. */
    public const string TOO_LARGE = 'package_too_large';

    /** The chunk is empty, too large, or not a valid upload. */
    public const string INVALID_CHUNK = 'invalid_chunk';

    /** The chunk does not continue the upload where it stands, or the upload stopped accepting bytes. */
    public const string UPLOAD_STATE = 'upload_state_invalid';

    /** Intake was asked for before every byte arrived. */
    public const string INCOMPLETE = 'upload_incomplete';

    /** The upload expired, or its staged archive is gone. */
    public const string EXPIRED = 'upload_expired';

    /** Intake is already queued or running, or already finished. */
    public const string NOT_QUEUEABLE = 'not_queueable';

    public const string STORAGE_FAILED = 'storage_failed';

    /** A ZIP that is neither an EHI Tables export nor a MyChart record ZIP. */
    public const string UNRECOGNIZED_EXPORT = 'unrecognized_export';

    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }

    public function status(): int
    {
        return match ($this->reason) {
            self::TOO_LARGE, self::INVALID_CHUNK => 422,
            self::STORAGE_FAILED => 503,
            default => 409,
        };
    }
}
