<?php

namespace App\Services\AgentApi\Client;

final readonly class AgentApiMultipart
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, AgentApiFile>  $files
     */
    public function __construct(
        public array $fields,
        public array $files,
    ) {}
}
