<?php

namespace App\Services\PHR\NativeBackup;

final readonly class PhrNativeRestorePlan
{
    /**
     * @param  array<string, array{create: int, skip: int, block: int}>  $tables
     * @param  array{create: int, skip: int, block: int, bytes: int}  $artifacts
     * @param  list<string>  $blockers
     * @param  array<string, array<string, string>>  $actions
     * @param  array<string, int>  $actorIds
     */
    public function __construct(
        public string $patientNativeId,
        public ?int $targetPatientId,
        public array $tables,
        public array $artifacts,
        public int $accessGrantCount,
        public bool $restoreAccessGrants,
        public array $blockers,
        public string $digest,
        public array $actions,
        public array $actorIds,
    ) {}

    /** @return array<string, mixed> */
    public function publicPayload(): array
    {
        return [
            'format' => PhrNativeBackupCatalog::FORMAT,
            'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'target' => $this->targetPatientId === null ? 'new_patient' : 'existing_patient',
            'tables' => $this->tables,
            'artifacts' => $this->artifacts,
            'access_grant_count' => $this->accessGrantCount,
            'restore_access_grants' => $this->restoreAccessGrants,
            'blockers' => $this->blockers,
            'plan_digest' => $this->digest,
            'confirmation_text' => 'RESTORE',
        ];
    }
}
