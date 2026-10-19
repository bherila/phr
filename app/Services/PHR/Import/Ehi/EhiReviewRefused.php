<?php

namespace App\Services\PHR\Import\Ehi;

use RuntimeException;
use Throwable;

/**
 * A review decision that was refused; nothing was written. `reason` is a fixed
 * code and the message is structural. A refusal raised by a mapping's apply()
 * (EhiApplyRefused) keeps the mapping's own reason code. status() is the HTTP
 * status a future REST/MCP surface should answer with: 422 for a request that
 * can never succeed as made, 409 for every state conflict.
 */
final class EhiReviewRefused extends RuntimeException
{
    /** The supplied preview digest is not the proposal's current one. */
    public const string STALE_PREVIEW = 'stale_preview';

    /** The target record changed since the preview. */
    public const string STALE_TARGET = 'stale_target';

    /** A revision the proposal relies on is gone or no longer matches its digest. */
    public const string STALE_SOURCE = 'stale_source';

    /** The mapping that produced the proposal is not registered or changed version. */
    public const string STALE_MAPPING = 'stale_mapping';

    /** The proposal was already decided differently, or superseded. */
    public const string NOT_PENDING = 'not_pending';

    /** The decision is unknown or not allowed for this proposal kind. */
    public const string DECISION_NOT_ALLOWED = 'decision_not_allowed';

    /** link_existing requires a record to link. */
    public const string LINK_TARGET_REQUIRED = 'link_target_required';

    /** The target record is gone, deleted, retracted, or belongs to another patient. */
    public const string TARGET_WITHDRAWN = 'target_withdrawn';

    /** A mapping refused with a reason that is not a fixed code. */
    public const string APPLY_REFUSED = 'apply_refused';

    private const array INVALID_REQUESTS = [self::DECISION_NOT_ALLOWED, self::LINK_TARGET_REQUIRED, 'decision_not_applicable', 'malformed_proposal'];

    public function __construct(public readonly string $reason, string $detail, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('EHI review refused (%s): %s.', $reason, $detail), 0, $previous);
    }

    public function status(): int
    {
        return in_array($this->reason, self::INVALID_REQUESTS, true) ? 422 : 409;
    }
}
