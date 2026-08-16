<?php

namespace App\DataTransferObjects\AgentApi;

final readonly class HealthLogCreateData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $externalId,
        public array $attributes,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        $externalId = (string) $validated['external_id'];
        unset($validated['external_id']);

        return new self($externalId, [
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'description' => $validated['description'] ?? null,
            'archived_at' => $validated['archived_at'] ?? null,
        ]);
    }
}
