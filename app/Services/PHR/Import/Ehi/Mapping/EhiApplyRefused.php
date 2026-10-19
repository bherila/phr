<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use RuntimeException;
use Throwable;

/**
 * Base for every refusal an EhiMapping::apply() throws. The proposal service
 * rolls back the review transaction and reports it as EhiReviewRefused with the
 * same `reason`, so a mapping refusal never leaves a partial write. `reason`
 * must be a fixed snake_case code and the message structural: never a source
 * value or a clinical detail.
 */
class EhiApplyRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('EHI apply refused (%s): %s.', $reason, $detail), 0, $previous);
    }
}
