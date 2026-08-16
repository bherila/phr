<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrDocument;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrPatient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PhrNativeRestorePlanner
{
    public function __construct(
        private readonly PhrNativeSnapshotService $snapshotService,
        private readonly PhrNativeRecordProjector $projector,
    ) {}

    public function plan(PhrNativeRestoreArchive $archive, int $actorUserId, bool $restoreAccessGrants): PhrNativeRestorePlan
    {
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
            try {
                foreach ($this->snapshotService->rows($targetPatientId) as $table => $rows) {
                    $definition = PhrNativeBackupCatalog::included()[$table];
                    foreach ($rows as $row) {
                        // Records created after the archive have no v1 identity yet
                        // and are outside this restore's conflict set. They remain
                        // untouched. A mapped current record still fails closed if
                        // one of its own relationships has lost identity metadata.
                        if (! isset($identitiesByRecordId[$table][(int) $row->id])) {
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
                    $isShare = $grantActorNativeId !== $ownerNativeId;
                    if ($isShare) {
                        $shareCount++;
                    }
                    if ($isShare && ! $restoreAccessGrants) {
                        $action = 'omit';
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
                $actions[$table][$nativeId] = $action;
                $tables[$table][$action === 'omit' ? 'skip' : $action]++;
                $knownArchiveRecords[$table][$nativeId] = true;
            }
        }

        $artifactPlan = ['create' => 0, 'skip' => 0, 'block' => 0, 'bytes' => 0];
        foreach ($archive->manifest['artifacts'] as $artifact) {
            $table = $artifact['kind'] === 'document' ? 'phr_documents' : 'phr_dicom_files';
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

    /** @param array<string, mixed> $artifact */
    private function currentArtifactMatches(string $table, int $recordId, array $artifact): bool
    {
        $row = DB::table($table)->where('id', $recordId)->first();
        if ($row === null) {
            return false;
        }
        $disk = $table === 'phr_documents' ? PhrDocument::STORAGE_DISK : 'phr_dicom';
        $path = $table === 'phr_documents' ? $row->storage_path : $row->r2_key;
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
