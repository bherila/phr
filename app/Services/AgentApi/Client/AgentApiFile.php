<?php

namespace App\Services\AgentApi\Client;

final readonly class AgentApiFile
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}
