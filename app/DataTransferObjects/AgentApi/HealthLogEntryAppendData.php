<?php

namespace App\DataTransferObjects\AgentApi;

final readonly class HealthLogEntryAppendData
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
            'occurred_at' => $validated['occurred_at'],
            'title' => $validated['title'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'intensity' => $validated['intensity'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'details' => $validated['details'] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    public function toRequestPayload(): array
    {
        $attributes = $this->attributes;
        if (($attributes['details'] ?? null) === []) {
            // MCP object schemas bind an empty {} to a PHP array. Restore the
            // declared JSON-object shape at the typed REST-client boundary.
            $attributes['details'] = (object) [];
        }

        return ['external_id' => $this->externalId, ...$attributes];
    }
}
