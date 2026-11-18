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
     * so their digest also names the storage key). A key column is patient_id,
     * an attribute, or a record relationship; a related row that is itself
     * created is new, so only a skipped (already present) one can collide, and
     * a null column never collides.
     *
     * @var array<string, list<list<string>>>
     */
    private const array UNIQUE_KEYS = [
        'phr_eob_corrections' => [['eob_id', 'ordinal']],
        'phr_eob_correction_lines' => [['correction_id', 'side', 'position']],
        'phr_ehi_sources' => [['patient_id', 'label']],
        'phr_ehi_packages' => [['patient_id', 'manifest_digest']],
        'phr_ehi_blobs' => [['patient_id', 'sha256']],
        'phr_ehi_package_members' => [['package_id', 'path_sha256']],
        'phr_ehi_table_accounts' => [['package_id', 'table_name']],
        'phr_ehi_entities' => [['source_id', 'table_name', 'key_digest']],
        'phr_ehi_revisions' => [['entity_id', 'content_digest']],
        'phr_ehi_occurrences' => [['member_id', 'line_number']], // sensitive-scan-ignore: package member FK, not an insurance member id
        'phr_ehi_proposals' => [['package_id', 'mapping', 'mapping_version', 'subject_key']],
        'phr_ehi_proposal_revisions' => [['proposal_id', 'revision_id']],
        'phr_ehi_record_links' => [['revision_id', 'immunization_id', 'link_kind'], ['revision_id', 'document_id', 'link_kind'], ['revision_id', 'lab_result_id', 'link_kind'], ['revision_id', 'office_visit_id', 'link_kind']],
    ];

    public function __construct(
        private readonly PhrNativeSnapshotService $snapshotService,
        private readonly PhrNativeRecordProjector $projector,
        private readonly PhrNativeIdentityRepository $identities,
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

        return $this->snapshotService->snapshot($targetPatientId ?? 0, $lockCurrentRows, fn (): PhrNativeRestorePlan => $this->planRecords(
            $archive, $actorUserId, $restoreAccessGrants, $lockCurrentRows, $patientNativeId, $ownerNativeId, $targetPatientId, $blockers,
        ));
    }

    /** @param list<string> $blockers */
    private function planRecords(PhrNativeRestoreArchive $archive, int $actorUserId, bool $restoreAccessGrants, bool $lockCurrentRows, string $patientNativeId, string $ownerNativeId, ?int $targetPatientId, array $blockers): PhrNativeRestorePlan
    {
        $actorIds = [$ownerNativeId => $actorUserId];
        $tables = [];
        $actions = [];
        $shareCount = 0;
        $legacyMigrations = [];
        $legacyHistoryArchive = ! isset($archive->manifest['tables']['phr_eob_corrections']);
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            $tables[$table] = ['create' => 0, 'skip' => 0, 'block' => 0];
            foreach ($archive->recordPages($table) as $page) {
                $recordIds = $targetPatientId === null ? [] : $this->identities->existingRecordIds($targetPatientId, $table, array_column($page, 'nativeId'));
                foreach ($this->currentRecordPages($page, $recordIds, $targetPatientId, $table, $lockCurrentRows) as [$records, $currentRows]) {
                    $projectionIdentities = $targetPatientId === null ? [] : $this->projectionIdentities($targetPatientId, $table, $definition, $currentRows);
                    foreach ($records as $record) {
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
                                if (! isset($actions[$relationship['table']][$relatedNativeId])) {
                                    $recordBlocked = true;
                                    $blockers[] = 'relationship_missing';
                                }
                            } elseif (! isset($actorIds[$relatedNativeId])) {
                                $actorId = $targetPatientId === null ? null : $this->identities->existingRecordId($targetPatientId, 'users', $relatedNativeId);
                                if ($actorId !== null && DB::table('users')->where('id', $actorId)->exists()) {
                                    $actorIds[$relatedNativeId] = $actorId;

                                    continue;
                                }
                                if ($action !== 'omit') {
                                    $recordBlocked = true;
                                    $blockers[] = 'actor_mapping_missing';
                                }
                            }
                        }

                        $recordId = $recordIds[$nativeId] ?? null;
                        if ($recordBlocked && $action !== 'omit') {
                            $action = 'block';
                        } elseif ($action !== 'omit' && $recordId !== null) {
                            $current = $currentRows[$recordId] ?? null;
                            if ($current !== null) {
                                try {
                                    $projected = $this->projector->project($targetPatientId, $table, $definition, $current, fn (string $relatedTable, int $id): ?string => $projectionIdentities[$relatedTable][$id] ?? null);
                                    // The archived record had its legacy document values mapped
                                    // on read; map the current row the same way, so a row that
                                    // still holds one compares equal to its own unchanged backup.
                                    $projected = PhrNativeDocumentCompatibility::normalize($table, $projected);
                                    if ($legacyHistoryArchive && $table === 'phr_eobs') {
                                        $legacy = PhrNativeEobCorrectionCompatibility::legacy($projected);
                                        if ($legacy !== []) {
                                            $historyHash = PhrNativeEobCorrectionCompatibility::historyHash($legacy);
                                            if (! hash_equals($historyHash, PhrNativeEobCorrectionCompatibility::historyHash($archive->legacyHistoryFor($nativeId)))
                                                || DB::table('phr_eob_corrections')->where('patient_id', $targetPatientId)->where('eob_id', $recordId)->exists()) {
                                                throw new NativeRestoreException('record_conflict');
                                            }
                                            $legacyMigrations[$nativeId] = ['record_id' => $recordId, 'history_hash' => $historyHash];
                                        }
                                        $projected = PhrNativeEobCorrectionCompatibility::normalize($projected);
                                    } elseif ($legacyHistoryArchive && $table === 'phr_eob_corrections') {
                                        $projected = PhrNativeEobCorrectionCompatibility::legacyView($projected);
                                    }
                                    $action = hash_equals((string) $projected['contentHash'], (string) $record['contentHash']) ? 'skip' : 'block';
                                    if ($action === 'block') {
                                        unset($legacyMigrations[$nativeId]);
                                    }
                                } catch (NativeRestoreException $exception) {
                                    $blockers[] = $exception->failureCategory;
                                    $action = 'block';
                                }
                            } else {
                                // Durable identity rows intentionally outlive record-level
                                // deletes. A missing row is recreated with that identity;
                                // a row merely excluded by a catalog row policy is a
                                // non-identical conflict and remains blocked.
                                $outsidePolicy = isset($definition['row_policy']) && DB::table($table)->where('id', $recordId)->exists();
                                $action = $outsidePolicy ? 'block' : 'create';
                            }
                            if ($action === 'block') {
                                $blockers[] = 'record_conflict';
                            }
                        }
                        if ($action === 'create' && $targetPatientId !== null && $this->collidesOnUniqueKey($table, $record, $targetPatientId, $actions)) {
                            $action = 'block';
                            $blockers[] = 'record_conflict';
                        }
                        $actions[$table][$nativeId] = $action;
                        $tables[$table][$action === 'omit' ? 'skip' : $action]++;
                    }
                }
            }
        }

        $artifactPlan = ['create' => 0, 'skip' => 0, 'block' => 0, 'bytes' => 0];
        foreach ($archive->manifest['artifacts'] as $artifact) {
            $table = PhrNativeBackupCatalog::artifactKinds()[(string) $artifact['kind']] ?? throw new NativeRestoreException('invalid_archive');
            $nativeId = (string) $artifact['recordNativeId'];
            $recordAction = $actions[$table][$nativeId] ?? 'block';
            $artifactAction = $recordAction === 'create' ? 'create' : ($recordAction === 'skip' ? 'skip' : 'block');
            if ($artifactAction === 'skip' && $targetPatientId !== null) {
                $recordId = $this->identities->existingRecordId($targetPatientId, $table, $nativeId);
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
            'legacyMigrations' => $legacyMigrations,
        ];
        $digest = hash('sha256', PhrNativeRecordCodec::canonicalJson($digestPayload));

        return new PhrNativeRestorePlan($patientNativeId, $targetPatientId, $tables, $artifactPlan, $shareCount, $restoreAccessGrants, $blockers, $digest, $actions, $actorIds, $legacyMigrations);
    }

    /**
     * Keep both archived and live payloads bounded even if target rows grew since
     * backup. Missing/policy-excluded rows retain their original conflict path.
     *
     * @param  list<array<string, mixed>>  $page
     * @param  array<string, int>  $recordIds
     * @return \Generator<int, array{list<array<string, mixed>>, array<int, \stdClass>}>
     */
    private function currentRecordPages(array $page, array $recordIds, ?int $patientId, string $table, bool $lockCurrentRows): \Generator
    {
        if ($patientId !== null) {
            $pending = array_column($page, null, 'nativeId');
            $nativeIds = array_flip($recordIds);
            foreach ($this->snapshotService->rowsByIds($patientId, $table, array_values($recordIds), $lockCurrentRows) as $currentRows) {
                $records = [];
                foreach ($currentRows as $id => $row) {
                    $nativeId = $nativeIds[$id];
                    $records[] = $pending[$nativeId];
                    unset($pending[$nativeId]);
                }
                yield [$records, $currentRows];
            }
            $page = array_values($pending);
        }
        if ($page !== []) {
            yield [$page, []];
        }
    }

    /**
     * Resolve inverse identities only for this bounded payload page and its FKs.
     * Every invocation rereads current state; neither positive nor missing IDs
     * survive the page/plan, and no unrelated patient history is materialized.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, \stdClass>  $rows
     * @return array<string, array<int, string>>
     */
    private function projectionIdentities(int $patientId, string $table, array $definition, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $requested = [$table => array_keys($rows)];
        if (! ($definition['root'] ?? false)) {
            $requested['phr_patients'][] = $patientId;
        }
        foreach ($rows as $row) {
            foreach ($definition['relationships'] ?? [] as $column => $relationship) {
                if ($row->{$column} !== null) {
                    $requested[$relationship['target']][] = (int) $row->{$column};
                }
            }
        }
        $identities = [];
        foreach ($requested as $relatedTable => $ids) {
            $identities[$relatedTable] = $this->identities->existingNativeIds($patientId, $relatedTable, $ids);
        }

        return $identities;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, string>>  $actions
     */
    private function collidesOnUniqueKey(string $table, array $record, int $targetPatientId, array $actions): bool
    {
        foreach (self::UNIQUE_KEYS[$table] ?? [] as $columns) {
            $query = DB::table($table);
            foreach ($columns as $column) {
                if ($column === 'patient_id') {
                    $value = $targetPatientId;
                } elseif (array_key_exists($column, $record['relationships'] ?? [])) {
                    $relationship = $record['relationships'][$column];
                    $relatedNativeId = is_array($relationship) && $relationship['kind'] === 'record' ? (string) $relationship['nativeId'] : null;
                    $value = $relatedNativeId !== null && ($actions[$relationship['table']][$relatedNativeId] ?? null) === 'skip'
                        ? $this->identities->existingRecordId($targetPatientId, $relationship['table'], $relatedNativeId)
                        : null;
                } else {
                    $value = $record['attributes'][$column] ?? null;
                }
                if ($value === null) {
                    continue 2;
                }
                $query->where($column, $value);
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
