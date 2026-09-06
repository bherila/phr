<?php

namespace App\DataTransferObjects\AgentApi;

final readonly class PatientCreateData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public array $attributes,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self([
            'display_name' => $validated['display_name'],
            'relationship' => $validated['relationship'] ?? null,
            'birth_date' => $validated['birth_date'] ?? null,
            'sex_at_birth' => $validated['sex_at_birth'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);
    }
}
