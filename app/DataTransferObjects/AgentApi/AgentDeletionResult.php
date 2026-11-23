<?php

namespace App\DataTransferObjects\AgentApi;

use Illuminate\Support\Carbon;

/**
 * What an agent delete did, and the tombstone it left: the same entry
 * changes.list returns for the record from then on, so the write response is
 * the later read of it.
 */
final readonly class AgentDeletionResult
{
    public const string DELETED = 'deleted';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        public string $outcome,
        public string $resourceType,
        public int $id,
        public int $patientId,
        public Carbon $changedAt,
    ) {}

    /** @return array{resource_type: string, id: int, patient_id: int, change_type: 'deleted', changed_at: string} */
    public function tombstone(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'id' => $this->id,
            'patient_id' => $this->patientId,
            'change_type' => 'deleted',
            'changed_at' => $this->changedAt->toIso8601String(),
        ];
    }
}
