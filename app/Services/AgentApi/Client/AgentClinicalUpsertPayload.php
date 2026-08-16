<?php

namespace App\Services\AgentApi\Client;

use Mcp\Exception\ToolCallException;

/** Typed response boundary for idempotent clinical writes. */
final readonly class AgentClinicalUpsertPayload
{
    /** @param array<string, mixed> $value */
    private function __construct(private array $value) {}

    public static function from(AgentApiTransportResponse $response): self
    {
        $payload = AgentApiPayload::from(
            $response,
            ['resource_type', 'patient_id', 'outcome', 'version', 'data'],
        )->toArray();
        if (! is_string($payload['resource_type'])
            || ! is_int($payload['patient_id'])
            || ! in_array($payload['outcome'], ['created', 'updated', 'unchanged'], true)
            || ! is_string($payload['version'])
            || preg_match('/\A[a-f0-9]{64}\z/', $payload['version']) !== 1
            || ! is_array($payload['data'])
            || array_is_list($payload['data'])) {
            throw new ToolCallException('The PHR API returned an invalid response.');
        }

        return new self($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->value;
    }
}
