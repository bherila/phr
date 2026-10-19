<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrDocument;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PhrNativeRestorePlanner
{
    /**
     * Unique keys a created row could collide on with a row the target already
     * holds under another identity (blob rows are content-addressed per patient,
     * so their digest also names the storage key). Each key is scoped by
     * patient_id or by a parent FK; a parent that is itself created is new, so
     * only a skipped (already present) parent can collide.
     *
     * @var array<string, list<array{scope: string, columns: list<string>}>>
     */
    private const array UNIQUE_KEYS = [
        'phr_ehi_sources' => [['scope' => 'patient_id', 'columns' => ['label']]],
        'phr_ehi_packages' => [['scope' => 'patient_id', 'columns' => ['manifest_digest']]],
        'phr_ehi_blobs' => [['scope' => 'patient_id', 'columns' => ['sha256']]],
        'phr_ehi_package_members' => [['scope' => 'package_id', 'columns' => ['path_sha256']]],
        'phr_ehi_table_accounts' => [['scope' => 'package_id', 'columns' => ['table_name']]],
    ];

    public function __construct(
        private readonly PhrNativeSnapshotService $snapshotService,
        private readonly PhrNativeRecordProjector $projector,
    ) {}

    public function plan(
        PhrNativeRestoreArchive $archive,
        int $actorUserId,
        bool $restoreAccessGrants,
        bool $lockCurrentRows = false,
    ): PhrNativeRestorePlan {
        $patientNativeId = (string) $archive->manifest['patientNativeId'];
        $rootRecords = iterator_to_array($archive->records('phr_patients'), false);
        $root = $rootRecords[0] ?? throw new NativeRestoreException('invalid_archive');
        $ownerNativeId = (string) ($root['relationships']['owner_user_id']['nativeId'] ?? '');

        $matches = PhrNativeRecordIdentity::query()
            ->where('record_table', 'phr_patients')
            ->where('native_id', $patientNativeId)
            ->pluck('patient_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $blockers = [];
        $targetPatientId = null;
        if ($matches->count() > 1) {
            $blockers[] = 'ambiguous_patient_identity';
        } elseif ($matches->count() === 1) {
            $candidateId = $matches->first();
            $candidate = PhrPatient::query()->find($candidateId);
            if ($candidate === null) {
                $blockers[] = 'current_identity_missing';
            } elseif ((int) $candidate->owner_user_id !== $actorUserId) {
                $blockers[] = 'patient_not_owned';
            } else {
                $targetPatientId = $candidateId;
            }
        }

        $identitiesByNativeId = [];
        $identitiesByRecordId = [];
        $currentHashes = [];
        $actorIds = [$ownerNativeId => $actorUserId];
        if ($targetPatientId !== null) {
            $identityRows = PhrNativeRecordIdentity::query()->where('patient_id', $targetPatientId)->get();
            foreach ($identityRows as $identity) {
                $table = (string) $identity->record_table;
                $nativeId = (string) $identity->native_id;
                $recordId = (int) $identity->record_id;
                $identitiesByNativeId[$table][$nativeId] = $recordId;
                $identitiesByRecordId[$table][$recordId] = $nativeId;
                if ($table === 'users' && DB::table('users')->where('id', $recordId)->exists()) {
                    $actorIds[$nativeId] = $recordId;
                }
            }
            $actorIds[$ownerNativeId] = $actorUserId;
            $archived = [];
            foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
                foreach ($archive->records($table) as $record) {
                    $archived[$table][(string) $record['nativeId']] = true;
                }
            }
            try {
                foreach ($this->snapshotService->rows($targetPatientId, $lockCurrentRows) as $table => $rows) {
                    $definition = PhrNativeBackupCatalog::included()[$table];
                    foreach ($rows as $row) {
                        // Records the archive does not contain (created after it,
                        // whether or not they have an identity yet: patients,
                        // documents and EOBs get theirs when written) are outside
                        // this restore's conflict set. They remain untouched. A
                        // mapped current record still fails closed if one of its
                        // own relationships has lost identity metadata.
                        $nativeId = $identitiesByRecordId[$table][(int) $row->id] ?? null;
                        if ($nativeId === null || ! isset($archived[$table][$nativeId])) {
                            continue;
                        }
                        $projected = $this->projector->project($targetPatientId, $table, $definition, $row, $identitiesByRecordId);
                        $currentHashes[$table][$projected['nativeId']] = $projected['contentHash'];
                    }
                }
            } catch (NativeRestoreException $exception) {
                $blockers[] = $exception->failureCategory;
            }
        }

        $tables = [];
        $actions = [];
        $knownArchiveRecords = [];
        $shareCount = 0;
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            $tables[$table] = ['create' => 0, 'skip' => 0, 'block' => 0];
            foreach ($archive->records($table) as $record) {
                $nativeId = (string) $record['nativeId'];
                $action = 'create';
                $recordBlocked = false;

                $isShare = false;
                if ($table === 'phr_patient_user_access') {
                    $grantActorNativeId = (string) ($record['relationships']['user_id']['nativeId'] ?? '');
                    $accessLevel = $record['attributes']['access_level'] ?? null;
                    $isShare = $grantActorNativeId !== $ownerNativeId;
                    if ($isShare) {
                        $shareCount++;
                    }
                    if ($isShare && ! $restoreAccessGrants) {
                        $action = 'omit';
                    }
                    $validAccessLevel = $isShare
                        ? in_array($accessLevel, [PhrPatientUserAccess::LEVEL_MANAGER, PhrPatientUserAccess::LEVEL_VIEWER], true)
                        : $accessLevel === PhrPatientUserAccess::LEVEL_OWNER;
                    if (! $validAccessLevel && $action !== 'omit') {
                        $recordBlocked = true;
                        $blockers[] = 'invalid_access_grant';
                    }
                }

                foreach ($record['relationships'] as $column => $relationship) {
                    if ($relationship === null) {
                        continue;
                    }
                    $relatedNativeId = (string) $relationship['nativeId'];
                    if ($relationship['kind'] === 'record') {
                        if (! isset($knownArchiveRecords[$relationship['table']][$relatedNativeId])) {
                            $recordBlocked = true;
                            $blockers[] = 'relationship_missing';
                        }
                    } elseif (! isset($actorIds[$relatedNativeId])) {
                        if ($action !== 'omit') {
                            $recordBlocked = true;
                            $blockers[] = 'actor_mapping_missing';
                        }
                    }
                }

                if ($recordBlocked && $action !== 'omit') {
                    $action = 'block';
                } elseif ($action !== 'omit' && isset($identitiesByNativeId[$table][$nativeId])) {
                    if (isset($currentHashes[$table][$nativeId])) {
                        $action = hash_equals((string) $currentHashes[$table][$nativeId], (string) $record['contentHash']) ? 'skip' : 'block';
                    } else {
                        // Durable identity rows intentionally outlive record-level
                        // deletes. A missing row is recreated with that identity;
                        // a row merely excluded by a catalog row policy is a
                        // non-identical conflict and remains blocked.
                        $recordId = $identitiesByNativeId[$table][$nativeId];
                        $outsidePolicy = isset($definition['row_policy']) && DB::table($table)->where('id', $recordId)->exists();
                        $action = $outsidePolicy ? 'block' : 'create';
                    }
                    if ($action === 'block') {
                        $blockers[] = 'record_conflict';
                    }
                }
                if ($action === 'create' && $targetPatientId !== null && $this->collidesOnUniqueKey($table, $record, $targetPatientId, $actions, $identitiesByNativeId)) {
                    $action = 'block';
                    $blockers[] = 'record_conflict';
                }
                $actions[$table][$nativeId] = $action;
                $tables[$table][$action === 'omit' ? 'skip' : $action]++;
                $knownArchiveRecords[$table][$nativeId] = true;
            }
        }

        $artifactPlan = ['create' => 0, 'skip' => 0, 'block' => 0, 'bytes' => 0];
        foreach ($archive->manifest['artifacts'] as $artifact) {
            $table = PhrNativeBackupCatalog::artifactKinds()[(string) $artifact['kind']] ?? throw new NativeRestoreException('invalid_archive');
            $nativeId = (string) $artifact['recordNativeId'];
            $recordAction = $actions[$table][$nativeId] ?? 'block';
            $artifactAction = $recordAction === 'create' ? 'create' : ($recordAction === 'skip' ? 'skip' : 'block');
            if ($artifactAction === 'skip' && $targetPatientId !== null) {
                $recordId = $identitiesByNativeId[$table][$nativeId] ?? null;
                if (! is_int($recordId) || ! $this->currentArtifactMatches($table, $recordId, $artifact)) {
                    $artifactAction = 'block';
                    $blockers[] = 'artifact_conflict';
                }
            }
            $artifactPlan[$artifactAction]++;
            $artifactPlan['bytes'] += (int) $artifact['size'];
            if ($artifactAction === 'block') {
                $blockers[] = 'artifact_conflict';
            }
        }

        $blockers = array_values(array_unique($blockers));
        sort($blockers);
        $digestPayload = [
            'archiveSha256' => $archive->sha256,
            'patientNativeId' => $patientNativeId,
            'targetPatientId' => $targetPatientId,
            'tables' => $tables,
            'artifacts' => $artifactPlan,
            'accessGrantCount' => $shareCount,
            'restoreAccessGrants' => $restoreAccessGrants,
            'blockers' => $blockers,
        ];
        $digest = hash('sha256', PhrNativeRecordCodec::canonicalJson($digestPayload));

        return new PhrNativeRestorePlan($patientNativeId, $targetPatientId, $tables, $artifactPlan, $shareCount, $restoreAccessGrants, $blockers, $digest, $actions, $actorIds);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, string>>  $actions
     * @param  array<string, array<string, int>>  $identitiesByNativeId
     */
    private function collidesOnUniqueKey(string $table, array $record, int $targetPatientId, array $actions, array $identitiesByNativeId): bool
    {
        foreach (self::UNIQUE_KEYS[$table] ?? [] as $key) {
            if ($key['scope'] === 'patient_id') {
                $scopeId = $targetPatientId;
            } else {
                $relationship = $record['relationships'][$key['scope']] ?? null;
                if (! is_array($relationship) || $relationship['kind'] !== 'record') {
                    continue;
                }
                $parentNativeId = (string) $relationship['nativeId'];
                if (($actions[$relationship['table']][$parentNativeId] ?? null) !== 'skip') {
                    continue;
                }
                $scopeId = $identitiesByNativeId[$relationship['table']][$parentNativeId] ?? null;
                if ($scopeId === null) {
                    continue;
                }
            }
            $query = DB::table($table)->where($key['scope'], $scopeId);
            foreach ($key['columns'] as $column) {
                $query->where($column, $record['attributes'][$column] ?? null);
            }
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $artifact */
    private function currentArtifactMatches(string $table, int $recordId, array $artifact): bool
    {
        $row = DB::table($table)->where('id', $recordId)->first();
        if ($row === null) {
            return false;
        }
        [$disk, $path] = match ($table) {
            'phr_documents' => [PhrDocument::STORAGE_DISK, $row->storage_path],
            'phr_ehi_blobs' => [(string) $row->storage_disk, $row->storage_path],
            default => ['phr_dicom', $row->r2_key],
        };
        if (! is_string($path) || $path === '') {
            return false;
        }
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            return false;
        }
        try {
            $context = hash_init('sha256');
            $size = hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return $size === $artifact['size'] && hash_equals((string) $artifact['sha256'], hash_final($context));
    }
}
