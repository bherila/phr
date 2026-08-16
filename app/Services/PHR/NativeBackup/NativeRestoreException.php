<?php

namespace App\Services\PHR\NativeBackup;

use RuntimeException;

final class NativeRestoreException extends RuntimeException
{
    public function __construct(public readonly string $failureCategory)
    {
        parent::__construct($failureCategory);
    }
}
