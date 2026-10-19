<?php

namespace App\Services\PHR\Import\Ehi;

use RuntimeException;
use Throwable;

/**
 * An intake that stopped after its package row existed. The package keeps what
 * was preserved and a retry resumes it. `reason` is a fixed code and the message
 * is structural; neither ever names a member or carries a source value.
 */
final class EhiIntakeFailed extends RuntimeException
{
    /** Member bytes differ from what the first pass read. */
    public const string SOURCE_CHANGED = 'source_changed';

    /** Another intake of the same package took over the lease. */
    public const string LEASE_LOST = 'lease_lost';

    /** The storage disk refused or lost a write. */
    public const string STORAGE_FAILED = 'storage_failed';

    /** Bytes read back from storage differ from the bytes written. */
    public const string STORED_OBJECT_MISMATCH = 'stored_object_mismatch';

    public function __construct(public readonly string $reason, string $detail, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('EHI intake failed (%s): %s.', $reason, $detail), 0, $previous);
    }
}
