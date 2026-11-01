<?php

namespace App\Services\AgentApi;

use RuntimeException;

/** The fill's stored NDC changed between preparing a write and locking the row; prepare again. */
final class StaleNdcPreparation extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The fill changed while the write was being prepared.');
    }
}
