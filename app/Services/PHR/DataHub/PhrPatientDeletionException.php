<?php

namespace App\Services\PHR\DataHub;

use RuntimeException;

final class PhrPatientDeletionException extends RuntimeException
{
    public function __construct(public readonly string $failureCategory)
    {
        parent::__construct('Patient deletion could not proceed.');
    }
}
