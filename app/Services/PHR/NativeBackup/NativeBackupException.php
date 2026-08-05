<?php

namespace App\Services\PHR\NativeBackup;

use RuntimeException;

final class NativeBackupException extends RuntimeException
{
    public function __construct(public readonly string $failureCategory)
    {
        // The exception may be persisted by Laravel's failed-job provider. Keep it
        // deliberately generic: no source key, filename, record value, or path.
        parent::__construct('Native backup generation failed.');
    }
}
