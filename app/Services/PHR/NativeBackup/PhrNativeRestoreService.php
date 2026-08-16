<?php

namespace App\Services\PHR\NativeBackup;

use App\Jobs\PHR\ApplyPhrNativeRestoreJob;
use App\Jobs\PHR\PreviewPhrNativeRestoreJob;
use App\Models\PhrDocument;
use App\Models\PhrNativeRecordIdentity;
use App\Models\PhrNativeRestoreAttempt;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class PhrNativeRestoreService
{
    public function __construct(
        private readonly PhrNativeRestoreArchiveReader $reader,
        private readonly PhrNativeRestorePlanner $planner,
    ) {}

    public function createPreview(UploadedFile $upload, int $actorUserId, bool $restoreAccessGrants): PhrNativeRestoreAttempt
    {
        $size = $upload->getSize();
        if (! is_int($size)) {
            throw new NativeRestoreException('invalid_upload');
        }
        $attempt = $this->startUpload($actorUserId, $size, $restoreAccessGrants);
        $attempt = $this->appendChunk($attempt, $actorUserId, $upload, 0);

        return $this->queuePreview($attempt, $actorUserId);
    }

    public function startUpload(int $actorUserId, int $size, bool $restoreAccessGrants): PhrNativeRestoreAttempt
    {
        $maxBytes = (int) config('phr.native_backup_max_uncompressed_bytes');
        if ($size < 1 || $size > $maxBytes) {
            throw new NativeRestoreException('size_limit');
        }
        $storageDisk = 'phr_exports';
        $storagePath = PhrStorageKey::nativeRestoreSource((string) Str::uuid());
        try {
            if (! Storage::disk($storageDisk)->put($storagePath, '')) {
                throw new NativeRestoreException('source_storage_failed');
            }
        } catch (Throwable $exception) {
            try {
                Storage::disk($storageDisk)->delete($storagePath);
            } catch (Throwable) {
                // The generic pruner can reclaim an unreferenced partial key.
            }
            throw $exception instanceof NativeRestoreException ? $exception : new NativeRestoreException('source_storage_failed');
        }

        try {
            return PhrNativeRestoreAttempt::query()->create([
                'actor_user_id' => $actorUserId,
                'source_storage_disk' => $storageDisk,
                'source_storage_path' => $storagePath,
                'source_file_size_bytes' => $size,
                'uploaded_bytes' => 0,
                'access_grant_count' => 0,
                'restore_access_grants' => $restoreAccessGrants,
                'status' => PhrNativeRestoreAttempt::STATUS_UPLOADING,
                'expires_at' => now()->addDays((int) config('phr.native_restore_source_retention_days')),
            ]);
        } catch (Throwable) {
            Storage::disk($storageDisk)->delete($storagePath);
            throw new NativeRestoreException('internal_error');
        }
    }

    public function appendChunk(PhrNativeRestoreAttempt $attempt, int $actorUserId, UploadedFile $chunk, int $offset): PhrNativeRestoreAttempt
    {
        $chunkSize = $chunk->getSize();
        $maxChunkBytes = (int) config('phr.native_restore_chunk_bytes');
        if (! $chunk->isValid() || ! is_int($chunkSize) || $chunkSize < 1 || $chunkSize > $maxChunkBytes) {
            throw new NativeRestoreException('invalid_upload_chunk');
        }

        return DB::transaction(function () use ($attempt, $actorUserId, $chunk, $chunkSize, $offset): PhrNativeRestoreAttempt {
            $current = PhrNativeRestoreAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->actor_user_id !== $actorUserId) {
                throw new NativeRestoreException('not_found');
            }
            if ($current->status !== PhrNativeRestoreAttempt::STATUS_UPLOADING
                || $current->source_storage_path === null
                || $current->expires_at->isPast()
                || $offset !== (int) $current->uploaded_bytes
                || $chunkSize > (int) $current->source_file_size_bytes - $offset) {
                throw new NativeRestoreException('upload_state_invalid');
            }

            $disk = Storage::disk($current->source_storage_disk);
            $targetPath = $disk->path($current->source_storage_path);
            $actualSize = filesize($targetPath);
            if ($actualSize !== $offset) {
                throw new NativeRestoreException('upload_state_invalid');
            }
            $source = fopen($chunk->getRealPath(), 'rb');
            $target = fopen($targetPath, 'ab');
            if (! is_resource($source) || ! is_resource($target)) {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                throw new NativeRestoreException('source_storage_failed');
            }
            try {
                $written = stream_copy_to_stream($source, $target);
                $flushed = fflush($target);
            } finally {
                fclose($source);
                fclose($target);
            }
            if ($written !== $chunkSize || ! $flushed) {
                throw new NativeRestoreException('source_storage_failed');
            }
            $current->update(['uploaded_bytes' => $offset + $chunkSize]);

            return $current;
        }, 3);
    }

    public function queuePreview(PhrNativeRestoreAttempt $attempt, int $actorUserId): PhrNativeRestoreAttempt
    {
        $queued = DB::transaction(function () use ($attempt, $actorUserId): PhrNativeRestoreAttempt {
            $current = PhrNativeRestoreAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->actor_user_id !== $actorUserId) {
                throw new NativeRestoreException('not_found');
            }
            if ($current->status !== PhrNativeRestoreAttempt::STATUS_UPLOADING
                || $current->source_storage_path === null
                || (int) $current->uploaded_bytes !== (int) $current->source_file_size_bytes
                || $current->expires_at->isPast()) {
                throw new NativeRestoreException('upload_incomplete');
            }
            $current->update(['status' => PhrNativeRestoreAttempt::STATUS_PREVIEW_PENDING]);

            return $current;
        });
        try {
            PreviewPhrNativeRestoreJob::dispatch((int) $queued->id);
        } catch (Throwable) {
            $queued->update([
                'status' => PhrNativeRestoreAttempt::STATUS_FAILED,
                'failure_category' => 'preview_queue_failed',
                'completed_at' => now(),
            ]);
        }

        return $queued->refresh();
    }

    public function preview(PhrNativeRestoreAttempt $attempt): PhrNativeRestoreAttempt
    {
        $attempt->update([
            'status' => PhrNativeRestoreAttempt::STATUS_PREVIEW_PROCESSING,
            'failure_category' => null,
            'completed_at' => null,
        ]);
        try {
            if ($attempt->actor_user_id === null
                || $attempt->source_storage_path === null
                || $attempt->expires_at->isPast()) {
                throw new NativeRestoreException('preview_expired');
            }
            $archive = $this->reader->openFromStorage($attempt->source_storage_disk, $attempt->source_storage_path);
            $plan = $this->planner->plan($archive, (int) $attempt->actor_user_id, (bool) $attempt->restore_access_grants);
            $attempt->update([
                'archive_sha256' => $archive->sha256,
                'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
                'patient_native_id' => $plan->patientNativeId,
                'target_patient_root_id' => $plan->targetPatientId,
                'plan_digest' => $plan->digest,
                'plan_counts_json' => [
                    'tables' => $plan->tables,
                    'artifacts' => $plan->artifacts,
                    'blockers' => $plan->blockers,
                ],
                'access_grant_count' => $plan->accessGrantCount,
                'status' => PhrNativeRestoreAttempt::STATUS_PREVIEW_READY,
                'failure_category' => null,
            ]);

            return $attempt->refresh();
        } catch (Throwable $exception) {
            $category = $exception instanceof NativeRestoreException ? $exception->failureCategory : 'invalid_archive';
            $attempt->update([
                'status' => PhrNativeRestoreAttempt::STATUS_FAILED,
                'failure_category' => $category,
                'completed_at' => now(),
            ]);
            throw new NativeRestoreException($category);
        }
    }

    public function markPreviewQueueFailure(int $attemptId): void
    {
        PhrNativeRestoreAttempt::query()
            ->whereKey($attemptId)
            ->whereIn('status', [PhrNativeRestoreAttempt::STATUS_PREVIEW_PENDING, PhrNativeRestoreAttempt::STATUS_PREVIEW_PROCESSING, PhrNativeRestoreAttempt::STATUS_FAILED])
            ->update([
                'status' => PhrNativeRestoreAttempt::STATUS_FAILED,
                'failure_category' => 'preview_queue_failed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function queue(PhrNativeRestoreAttempt $attempt, int $actorUserId, string $planDigest, bool $restoreAccessGrants): PhrNativeRestoreAttempt
    {
        $queued = DB::transaction(function () use ($attempt, $actorUserId, $planDigest, $restoreAccessGrants): PhrNativeRestoreAttempt {
            $current = PhrNativeRestoreAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->actor_user_id !== $actorUserId) {
                throw new NativeRestoreException('not_found');
            }
            if ($current->status !== PhrNativeRestoreAttempt::STATUS_PREVIEW_READY
                || $current->source_storage_path === null
                || $current->expires_at->isPast()) {
                throw new NativeRestoreException('preview_expired');
            }
            if ($current->plan_digest === null || ! hash_equals($current->plan_digest, $planDigest)
                || (bool) $current->restore_access_grants !== $restoreAccessGrants) {
                throw new NativeRestoreException('preview_changed');
            }
            $counts = $current->plan_counts_json;
            $hasBlockedRecords = false;
            foreach (($counts['tables'] ?? []) as $tableCounts) {
                if (is_array($tableCounts) && (int) ($tableCounts['block'] ?? 0) > 0) {
                    $hasBlockedRecords = true;
                    break;
                }
            }
            if (($counts['blockers'] ?? []) !== [] || $hasBlockedRecords || (int) ($counts['artifacts']['block'] ?? 0) > 0) {
                throw new NativeRestoreException('restore_blocked');
            }
            $current->update([
                'status' => PhrNativeRestoreAttempt::STATUS_PENDING,
                'failure_category' => null,
                // A confirmation near the end of the upload retention window
                // still gets a full day for the queue to start. Active work is
                // excluded from source purging.
                'expires_at' => $current->expires_at->max(now()->addDay()),
            ]);

            return $current;
        });

        try {
            ApplyPhrNativeRestoreJob::dispatch((int) $queued->id);
        } catch (Throwable) {
            $queued->update([
                'status' => PhrNativeRestoreAttempt::STATUS_FAILED,
                'failure_category' => 'restore_queue_failed',
                'completed_at' => now(),
            ]);
        }

        return $queued->refresh();
    }

    public function apply(PhrNativeRestoreAttempt $attempt): PhrNativeRestoreAttempt
    {
        $written = new PhrNativeRestoreWrittenArtifacts;
        $graphCommitted = $attempt->status === PhrNativeRestoreAttempt::STATUS_FINALIZING;
        try {
            if (! $graphCommitted) {
                $attempt->update([
                    'status' => PhrNativeRestoreAttempt::STATUS_PROCESSING,
                    'failure_category' => null,
                    'completed_at' => null,
                ]);
                if ($attempt->actor_user_id === null
                    || $attempt->source_storage_path === null
                    || $attempt->archive_sha256 === null
                    || $attempt->patient_native_id === null
                    || $attempt->plan_digest === null
                    || $attempt->expires_at->isPast()) {
                    throw new NativeRestoreException('preview_expired');
                }
                $archive = $this->reader->openFromStorage($attempt->source_storage_disk, $attempt->source_storage_path);
                if (! hash_equals($attempt->archive_sha256, $archive->sha256)) {
                    throw new NativeRestoreException('archive_changed');
                }

                try {
                    Cache::lock('phr-native-restore:'.hash('sha256', $attempt->patient_native_id), 3660)->block(30, function () use ($attempt, $archive, $written, &$graphCommitted): void {
                        DB::transaction(function () use ($attempt, $archive, $written): void {
                            $lockedAttempt = PhrNativeRestoreAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                            DB::table('users')->where('id', $lockedAttempt->actor_user_id)->lockForUpdate()->first();
                            if ($lockedAttempt->target_patient_root_id !== null) {
                                DB::table('phr_patients')->where('id', $lockedAttempt->target_patient_root_id)->lockForUpdate()->first();
                            }
                            $plan = $this->planner->plan(
                                $archive,
                                (int) $lockedAttempt->actor_user_id,
                                (bool) $lockedAttempt->restore_access_grants,
                                lockCurrentRows: true,
                            );
                            if (! hash_equals($lockedAttempt->plan_digest, $plan->digest) || $plan->blockers !== []) {
                                throw new NativeRestoreException('preview_changed');
                            }

                            $patientId = $this->applyPlan($archive, $plan, $written, (int) $lockedAttempt->id);
                            $lockedAttempt->update([
                                'target_patient_root_id' => $patientId,
                                // Keep the attempt visibly non-terminal until a
                                // second transaction publishes the ingestion
                                // watermark. Pending identities remain included in
                                // update windows during this brief boundary.
                                'status' => PhrNativeRestoreAttempt::STATUS_FINALIZING,
                                'failure_category' => null,
                                'completed_at' => null,
                            ]);
                        }, 3);
                        $graphCommitted = true;
                        $this->finalizeRestore((int) $attempt->id);
                    });
                } catch (LockTimeoutException) {
                    throw new NativeRestoreException('restore_busy');
                }
            } else {
                // A retry after the graph transaction committed must only publish
                // its pending watermarks; replaying artifact writes could corrupt
                // an otherwise successful restore.
                $this->finalizeRestore((int) $attempt->id);
            }
        } catch (Throwable $exception) {
            if (! $graphCommitted) {
                foreach (array_reverse($written->items) as [$disk, $path]) {
                    try {
                        Storage::disk($disk)->delete($path);
                    } catch (Throwable) {
                        // The fixed failure category remains safe; the source keys are
                        // never copied into logs or audit metadata.
                    }
                }
                $category = $exception instanceof NativeRestoreException ? $exception->failureCategory : 'internal_error';
                try {
                    $attempt->update([
                        'status' => PhrNativeRestoreAttempt::STATUS_FAILED,
                        'failure_category' => $category,
                        'completed_at' => now(),
                    ]);
                } catch (Throwable) {
                    // The worker will invoke markQueueFailure if even the fixed audit
                    // update cannot be persisted.
                }

                throw new NativeRestoreException($category);
            }

            // The patient graph is already durable. Preserve its artifacts and
            // finalizing status so the queue can retry only the atomic watermark.
            throw new NativeRestoreException('internal_error');
        }

        // Source retention cleanup is deliberately outside the graph transaction
        // and failure path. Once patient rows commit, a source-delete outage must
        // never remove their newly live artifacts or relabel success as failure.
        try {
            if (Storage::disk($attempt->source_storage_disk)->delete($attempt->source_storage_path)) {
                $attempt->update(['source_storage_path' => null]);
            }
        } catch (Throwable) {
            // The daily purger retries this operational copy.
        }

        return $attempt->refresh();
    }

    public function markQueueFailure(int $attemptId): void
    {
        $attempt = PhrNativeRestoreAttempt::query()->find($attemptId);
        if ($attempt?->status === PhrNativeRestoreAttempt::STATUS_FINALIZING) {
            try {
                // The graph is already durable; exhausting the worker must not
                // strand its identities in the pending-watermark state.
                $this->finalizeRestore($attemptId);

                return;
            } catch (Throwable) {
                // Fall through to the fixed terminal failure marker if the
                // database cannot publish the watermark even in failed().
            }
        }

        PhrNativeRestoreAttempt::query()
            ->whereKey($attemptId)
            ->whereIn('status', [
                PhrNativeRestoreAttempt::STATUS_PENDING,
                PhrNativeRestoreAttempt::STATUS_PROCESSING,
                PhrNativeRestoreAttempt::STATUS_FINALIZING,
                PhrNativeRestoreAttempt::STATUS_FAILED,
            ])
            ->update([
                'status' => PhrNativeRestoreAttempt::STATUS_FAILED,
                'failure_category' => 'queue_failed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function applyPlan(
        PhrNativeRestoreArchive $archive,
        PhrNativeRestorePlan $plan,
        PhrNativeRestoreWrittenArtifacts $written,
        int $restoreAttemptId,
    ): int {
        $newIds = [];
        if ($plan->targetPatientId !== null) {
            foreach (PhrNativeRecordIdentity::query()->where('patient_id', $plan->targetPatientId)->get() as $identity) {
                $newIds[(string) $identity->record_table][(string) $identity->native_id] = (int) $identity->record_id;
            }
        }
        $patientId = $plan->targetPatientId ?? 0;
        $artifacts = [];
        foreach ($archive->manifest['artifacts'] as $artifact) {
            if (is_array($artifact) && is_string($artifact['recordNativeId'] ?? null)) {
                $artifacts[$artifact['recordNativeId']] = $artifact;
            }
        }

        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            foreach ($archive->records($table) as $record) {
                $nativeId = (string) $record['nativeId'];
                $action = $plan->actions[$table][$nativeId] ?? 'block';
                if ($action === 'omit') {
                    continue;
                }
                if ($action === 'block') {
                    throw new NativeRestoreException('restore_blocked');
                }
                if ($action === 'skip') {
                    if (! isset($newIds[$table][$nativeId])) {
                        throw new NativeRestoreException('current_identity_missing');
                    }

                    continue;
                }

                $data = [];
                foreach ($record['attributes'] as $column => $value) {
                    $data[$column] = PhrNativeRecordCodec::decodeValue($value);
                }
                foreach ($record['relationships'] as $column => $relationship) {
                    if ($relationship === null) {
                        $data[$column] = null;

                        continue;
                    }
                    $relatedNativeId = (string) $relationship['nativeId'];
                    if ($relationship['kind'] === 'actor') {
                        $data[$column] = $plan->actorIds[$relatedNativeId] ?? throw new NativeRestoreException('actor_mapping_missing');
                    } else {
                        $data[$column] = $newIds[$relationship['table']][$relatedNativeId] ?? throw new NativeRestoreException('relationship_missing');
                    }
                }

                $artifact = $artifacts[$nativeId] ?? null;
                $this->setOperationalColumns($table, $nativeId, $patientId, $record, $data, is_array($artifact) ? $artifact : null, $newIds);
                $newId = (int) DB::table($table)->insertGetId($data);
                $newIds[$table][$nativeId] = $newId;
                if ($table === 'phr_patients') {
                    $patientId = $newId;
                }
                $identity = PhrNativeRecordIdentity::query()
                    ->where('patient_id', $patientId)
                    ->where('record_table', $table)
                    ->where('native_id', $nativeId)
                    ->first();
                if ($identity === null) {
                    PhrNativeRecordIdentity::query()->create([
                        'patient_id' => $patientId,
                        'record_table' => $table,
                        'record_id' => $newId,
                        'native_id' => $nativeId,
                        'restored_at' => null,
                        'restore_attempt_id' => $restoreAttemptId,
                    ]);
                } else {
                    $identity->update([
                        'record_id' => $newId,
                        'restored_at' => null,
                        'restore_attempt_id' => $restoreAttemptId,
                    ]);
                }
                $this->markRestoredApiParent($table, $data, $patientId, $restoreAttemptId);

                if (is_array($artifact)) {
                    $disk = $table === 'phr_documents' ? PhrDocument::STORAGE_DISK : 'phr_dicom';
                    $path = $table === 'phr_documents' ? $data['storage_path'] : $data['r2_key'];
                    $written->add($disk, $path);
                    if (! $archive->copyArtifactTo((string) $artifact['path'], $disk, $path)) {
                        throw new NativeRestoreException('artifact_write_failed');
                    }
                }
            }
        }

        foreach ($plan->actorIds as $nativeId => $userId) {
            PhrNativeRecordIdentity::query()->firstOrCreate([
                'patient_id' => $patientId,
                'record_table' => 'users',
                'record_id' => $userId,
            ], ['native_id' => $nativeId]);
        }

        return $patientId;
    }

    /** @param array<string, mixed> $data */
    private function markRestoredApiParent(string $table, array $data, int $patientId, int $restoreAttemptId): void
    {
        $parent = match ($table) {
            'phr_patient_user_access' => ['phr_patients', $patientId],
            'phr_health_log_entries' => ['phr_health_logs', (int) ($data['health_log_id'] ?? 0)],
            default => null,
        };
        if ($parent === null || $parent[1] < 1) {
            return;
        }

        PhrNativeRecordIdentity::query()
            ->where('patient_id', $patientId)
            ->where('record_table', $parent[0])
            ->where('record_id', $parent[1])
            ->update([
                'restored_at' => null,
                'restore_attempt_id' => $restoreAttemptId,
            ]);
    }

    private function finalizeRestore(int $attemptId): void
    {
        DB::transaction(function () use ($attemptId): void {
            $attempt = PhrNativeRestoreAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status === PhrNativeRestoreAttempt::STATUS_COMPLETED) {
                return;
            }
            if ($attempt->status !== PhrNativeRestoreAttempt::STATUS_FINALIZING) {
                throw new NativeRestoreException('internal_error');
            }

            // This is deliberately sampled after the patient graph transaction
            // committed. Publishing it atomically with terminal status means the
            // timestamp never predates visibility; until then, the null watermark
            // plus restore_attempt_id is treated as pending/new by incremental reads.
            $visibleAt = now();
            PhrNativeRecordIdentity::query()
                ->where('restore_attempt_id', $attemptId)
                ->whereNull('restored_at')
                ->update(['restored_at' => $visibleAt]);
            $attempt->update([
                'status' => PhrNativeRestoreAttempt::STATUS_COMPLETED,
                'failure_category' => null,
                'completed_at' => $visibleAt,
            ]);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $artifact
     * @param  array<string, array<string, int>>  $newIds
     */
    private function setOperationalColumns(string $table, string $nativeId, int $patientId, array $record, array &$data, ?array $artifact, array $newIds): void
    {
        if ($table === 'phr_documents') {
            $data['genai_job_id'] = null;
            $data['storage_disk'] = PhrDocument::STORAGE_DISK;
            $data['storage_path'] = $artifact === null ? null : PhrStorageKey::document($patientId, $nativeId, 'document.bin');
        } elseif ($table === 'phr_dicom_uploads') {
            $data['r2_prefix'] = PhrStorageKey::dicomUpload($patientId, $nativeId);
            $data['error_message'] = null;
        } elseif ($table === 'phr_dicom_files') {
            $uploadNativeId = (string) $record['relationships']['upload_id']['nativeId'];
            $uploadId = $newIds['phr_dicom_uploads'][$uploadNativeId] ?? throw new NativeRestoreException('relationship_missing');
            $uploadPrefix = DB::table('phr_dicom_uploads')->where('id', $uploadId)->value('r2_prefix');
            if (! is_string($uploadPrefix)) {
                throw new NativeRestoreException('relationship_missing');
            }
            $data['r2_key'] = PhrStorageKey::dicomObject($uploadPrefix, (string) ($data['original_relative_path'] ?? ''));
        }
    }
}
