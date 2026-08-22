<?php

namespace App\Support\PHR;

/**
 * The review lifecycle for clinical records.
 *
 * Only a human acting in the browser may leave `pending_review`. Agents never
 * assert a status: the server writes `pending_review` on create and reopens
 * review on every effective agent edit, so unreviewed machine-written data can
 * never reach a clinical export.
 */
final class PhrReviewStatus
{
    public const string PENDING = 'pending_review';

    public const string CONFIRMED = 'confirmed';

    public const string REJECTED = 'rejected';

    /** Every persisted value. Rows predating agent writes default to `confirmed`. */
    public const array ALL = [self::PENDING, self::CONFIRMED, self::REJECTED];

    /** The decisions a browser reviewer may record. */
    public const array DECISIONS = [self::CONFIRMED, self::REJECTED];
}
