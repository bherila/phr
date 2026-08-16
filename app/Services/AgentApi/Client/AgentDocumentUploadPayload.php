<?php

namespace App\Services\AgentApi\Client;

use Mcp\Exception\ToolCallException;

final readonly class AgentDocumentUploadPayload
{
    /** @param array<string, mixed> $value */
    private function __construct(private array $value) {}

    public static function from(AgentApiTransportResponse $response): self
    {
        $payload = AgentApiPayload::from(
            $response,
            ['resource_type', 'patient_id', 'outcome', 'data'],
        )->toArray();
        if ($payload['resource_type'] !== 'document'
            || ! is_int($payload['patient_id'])
            || ! in_array($payload['outcome'], ['created', 'unchanged'], true)
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
