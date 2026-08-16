<?php

namespace App\Services\PHR\DataHub;

final readonly class PhrPatientDeletionPlan
{
    /**
     * @param  array<string, int>  $recordCounts
     * @param  list<array{disk: string, key: string, bytes: int|null}>  $artifacts
     * @param  list<string>  $blockers
     */
    public function __construct(
        public int $patientId,
        public array $recordCounts,
        public int $activeShareCount,
        public array $artifacts,
        public int $artifactBytes,
        public array $blockers,
        public string $digest,
    ) {}

    /** @return array<string, mixed> */
    public function publicPayload(): array
    {
        return [
            'patient_id' => $this->patientId,
            'record_counts' => $this->recordCounts,
            'database_row_count' => array_sum($this->recordCounts),
            'active_share_count' => $this->activeShareCount,
            'artifact_count' => count($this->artifacts),
            'artifact_bytes' => $this->artifactBytes,
            'blockers' => $this->blockers,
            'preview_digest' => $this->digest,
            'confirmation_text' => 'DELETE',
        ];
    }
}
