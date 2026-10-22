<?php

namespace App\Services\PHR\Import\Ehi;

use RuntimeException;
use Throwable;

/**
 * A projection that was refused or stopped. `reason` is a fixed code and the
 * message is structural; neither names a member, table, column, or value. Rows
 * already committed stay, and a retry converges on them.
 */
final class EhiProjectionRefused extends RuntimeException
{
    /** The package is not yet preserved and accounted. */
    public const string NOT_READY = 'not_ready';

    /** The package is not bound to a declared source. */
    public const string NOT_BOUND = 'not_bound';

    /** A stored member is missing or no longer hashes to its manifest digest. */
    public const string BLOB_UNVERIFIED = 'blob_unverified';

    /** Re-reading a table disagrees with the counts its account recorded. */
    public const string ACCOUNTING_MISMATCH = 'accounting_mismatch';

    /** The table was accounted under a different TSV dialect than projection reads. */
    public const string DIALECT_CHANGED = 'dialect_changed';

    /** Another projection or intake of this package took over its lease. */
    public const string LEASE_LOST = 'lease_lost';

    public const string PREVIEW_SUPERSEDED = 'preview_superseded';

    public const string RESOURCE_LIMIT = 'resource_limit';

    public function __construct(public readonly string $reason, string $detail, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('EHI projection refused (%s): %s.', $reason, $detail), 0, $previous);
    }
}
