<?php

namespace App\Services\AgentApi\Client;

use Mcp\Exception\ToolCallException;

/** Typed response boundary for import reads and mutations. */
final readonly class AgentImportPayload
{
    /** @param array<string, mixed> $value */
    private function __construct(private array $value) {}

    public static function page(AgentApiTransportResponse $response): self
    {
        return self::validate(AgentApiPayload::page($response)->toArray(), 'import_job', false);
    }

    public static function item(AgentApiTransportResponse $response): self
    {
        return self::validate(AgentApiPayload::item(
            $response,
            ['resource_type', 'patient_id', 'data'],
        )->toArray(), 'import_job', true);
    }

    public static function mutation(AgentApiTransportResponse $response, string $resourceType): self
    {
        $payload = AgentApiPayload::from(
            $response,
            ['resource_type', 'patient_id', 'outcome', 'data'],
        )->toArray();

        return self::validate($payload, $resourceType, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->value;
    }

    /** @param array<string, mixed> $payload */
    private static function validate(array $payload, string $resourceType, bool $requiresObjectData): self
    {
        if (($payload['resource_type'] ?? null) !== $resourceType
            || ! is_int($payload['patient_id'] ?? null)
            || ! is_array($payload['data'] ?? null)
            || ($requiresObjectData && array_is_list($payload['data']))) {
            throw new ToolCallException('The PHR API returned an invalid response.');
        }

        return new self($payload);
    }
}
