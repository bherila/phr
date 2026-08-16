<?php

namespace App\Services\AgentApi\Client;

interface AgentApiTransport
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  array<string, mixed>|null  $json
     */
    public function send(string $method, string $path, array $query = [], ?array $json = null): AgentApiTransportResponse;
}
