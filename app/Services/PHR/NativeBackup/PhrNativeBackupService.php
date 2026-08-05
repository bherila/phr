<?php

namespace App\Services\PHR\NativeBackup;

use App\Jobs\PHR\GeneratePhrNativeBackupJob;
use App\Models\PhrNativeBackup;
use App\Models\PhrNativeBackupAudit;
use App\Models\PhrPatient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class PhrNativeBackupService
{
    // Longer than the job timeout plus retry backoff, but finite so a queue entry
    // lost after commit cannot block this patient from requesting a backup forever.
    private const int ACTIVE_BACKUP_LEASE_MINUTES = 15;

    public function __construct(private readonly PhrNativeArchiveBuilder $archiveBuilder) {}

    public function createQueuedBackup(PhrPatient $patient, int $requestedByUserId): PhrNativeBackup
    {
        // Lock the aggregate root so concurrent requests cannot both observe an empty
        // backup set and enqueue duplicate multi-gigabyte work. Completed archives
        // are immutable snapshots and are never reused: a new explicit request must
        // capture hard deletes, concurrent edits, and the current schema version.
        [$backup, $dispatch] = DB::transaction(function () use ($patient, $requestedByUserId): array {
            $lockedPatient = PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $active = PhrNativeBackup::query()
                ->where('patient_id', $lockedPatient->id)
                ->whereIn('status', [
                    PhrNativeBackup::STATUS_PENDING,
                    PhrNativeBackup::STATUS_PROCESSING,
                ])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($active !== null) {
                $leaseExpired = $active->updated_at === null
                    || $active->updated_at->lte(now()->subMinutes(self::ACTIVE_BACKUP_LEASE_MINUTES));
                if ($leaseExpired) {
                    // Renew before redispatch so simultaneous requests cannot all
                    // enqueue the same recovery job. The job also has an overlap lock.
                    $active->touch();
                }

                return [$active, $leaseExpired];
            }

            return [PhrNativeBackup::query()->create([
                'patient_id' => $lockedPatient->id,
                'requested_by_user_id' => $requestedByUserId,
                'status' => PhrNativeBackup::STATUS_PENDING,
                'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
                'storage_disk' => 'phr_exports',
                // Retention starts only after bytes are ready to download. A paused
                // queue must not consume or outlive the promised retrieval window.
                'expires_at' => null,
            ]), true];
        });

        if ($dispatch) {
            try {
                GeneratePhrNativeBackupJob::dispatch($backup->id);
            } catch (\Throwable $exception) {
                // A synchronous queue-dispatch failure happens after the row commits.
                // Make it terminal immediately instead of waiting for lease recovery.
                $this->markQueueFailure($backup->id);

                throw $exception;
            }
        }

        return $backup;
    }

    public function generate(PhrNativeBackup $backup): PhrNativeBackup
    {
        $backup->update([
            'status' => PhrNativeBackup::STATUS_PROCESSING,
            'failure_category' => null,
        ]);

        $result = null;
        $storagePath = null;

        try {
            $patient = PhrPatient::query()->findOrFail($backup->patient_id);
            $result = $this->archiveBuilder->build($patient);
            $storagePath = 'phr/native-backups/'.$backup->id.'/'.Str::uuid().'.zip';
            $stream = fopen($result->path, 'rb');
            if ($stream === false) {
                throw new NativeBackupException('temporary_storage_failed');
            }

            try {
                $stored = Storage::disk($backup->storage_disk)->put($storagePath, $stream);
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw new NativeBackupException('output_storage_failed');
            }

            // Patient deletion takes the same patient-row lock before cleaning archive
            // bytes. Whichever operation wins is therefore complete: deletion sees this
            // path, or generation notices the deleted aggregate and removes its new file.
            $readyBackup = DB::transaction(function () use ($backup, $storagePath, $result): ?PhrNativeBackup {
                $patientExists = PhrPatient::query()
                    ->whereKey($backup->patient_id)
                    ->lockForUpdate()
                    ->first() !== null;
                $current = PhrNativeBackup::query()->whereKey($backup->id)->lockForUpdate()->first();

                if (! $patientExists || $current === null) {
                    return null;
                }

                $current->update([
                    'status' => PhrNativeBackup::STATUS_READY,
                    'storage_path' => $storagePath,
                    'file_size_bytes' => $result->fileSize,
                    'archive_sha256' => $result->sha256,
                    'counts_json' => $result->counts,
                    'failure_category' => null,
                    'generated_at' => now(),
                    'expires_at' => now()->addDays((int) config('phr.native_backup_retention_days', 7)),
                ]);

                return $current;
            });
            if ($readyBackup === null) {
                if (! Storage::disk($backup->storage_disk)->delete($storagePath)) {
                    throw new NativeBackupException('output_cleanup_failed');
                }
                $storagePath = null;

                throw new NativeBackupException('patient_deleted');
            }

            $backup = $readyBackup;
            $this->audit($backup, 'succeeded', null);
        } catch (NativeBackupException $exception) {
            $this->markFailed($backup, $exception->failureCategory);
            if ($storagePath !== null) {
                Storage::disk($backup->storage_disk)->delete($storagePath);
            }
            throw new NativeBackupException($exception->failureCategory);
        } catch (\Throwable) {
            $this->markFailed($backup, 'internal_error');
            if ($storagePath !== null) {
                Storage::disk($backup->storage_disk)->delete($storagePath);
            }
            throw new NativeBackupException('internal_error');
        } finally {
            if ($result !== null && is_file($result->path)) {
                @unlink($result->path);
            }
        }

        return $backup;
    }

    /**
     * Convert a worker-level failure (including a hard timeout) into a terminal state.
     * Domain failures have already recorded a more useful category and are preserved.
     */
    public function markQueueFailure(int $backupId): void
    {
        DB::transaction(function () use ($backupId): void {
            $backup = PhrNativeBackup::query()->whereKey($backupId)->lockForUpdate()->first();
            if ($backup === null || ! in_array($backup->status, [
                PhrNativeBackup::STATUS_PENDING,
                PhrNativeBackup::STATUS_PROCESSING,
            ], true)) {
                return;
            }

            $this->markFailed($backup, 'queue_failure');
        });
    }

    /** Delete a patient only after every native archive is durably untracked. */
    public function deletePatientAndBackups(PhrPatient $patient): void
    {
        while (true) {
            $backupId = PhrNativeBackup::query()
                ->where('patient_id', $patient->id)
                ->orderBy('id')
                ->value('id');

            if ($backupId === null) {
                $deleted = DB::transaction(function () use ($patient): bool {
                    $lockedPatient = PhrPatient::query()
                        ->whereKey($patient->id)
                        ->lockForUpdate()
                        ->first();
                    if ($lockedPatient === null) {
                        return true;
                    }

                    // A backup request can race between loop iterations. Holding the
                    // aggregate-root lock makes the empty check and patient deletion
                    // atomic with createQueuedBackup() and generate() finalization.
                    if (PhrNativeBackup::query()->where('patient_id', $patient->id)->exists()) {
                        return false;
                    }

                    $lockedPatient->delete();

                    return true;
                });

                if ($deleted) {
                    return;
                }

                continue;
            }

            // Each successful filesystem deletion and row deletion commits together.
            // If a later disk operation fails, earlier rows are not rolled back into
            // references to files that are already gone, and the patient remains.
            $removed = DB::transaction(function () use ($patient, $backupId): bool {
                $patientExists = PhrPatient::query()
                    ->whereKey($patient->id)
                    ->lockForUpdate()
                    ->first() !== null;
                if (! $patientExists) {
                    return true;
                }

                $backup = PhrNativeBackup::query()
                    ->where('patient_id', $patient->id)
                    ->whereKey($backupId)
                    ->lockForUpdate()
                    ->first();
                if ($backup === null) {
                    return true;
                }

                // A live worker may already have uploaded bytes not yet recorded on
                // this row, so keep the aggregate until finalization wins. Beyond the
                // lease (three times the hard worker timeout), processing is abandoned
                // and cannot permanently prevent patient deletion.
                if ($backup->status === PhrNativeBackup::STATUS_PROCESSING
                    && ($backup->updated_at === null
                        || $backup->updated_at->gt(now()->subMinutes(self::ACTIVE_BACKUP_LEASE_MINUTES)))) {
                    throw new NativeBackupInProgressException;
                }

                if ($backup->storage_path !== null
                    && ! Storage::disk($backup->storage_disk)->delete($backup->storage_path)) {
                    return false;
                }

                $backup->delete();

                return true;
            });

            if (! $removed) {
                throw new RuntimeException('Native backup storage cleanup failed.');
            }
        }
    }

    private function markFailed(PhrNativeBackup $backup, string $category): void
    {
        if (! PhrNativeBackup::query()->whereKey($backup->id)->exists()) {
            return;
        }

        $backup->update([
            'status' => PhrNativeBackup::STATUS_FAILED,
            'storage_path' => null,
            'file_size_bytes' => null,
            'archive_sha256' => null,
            'counts_json' => null,
            'failure_category' => $category,
            'generated_at' => null,
            'expires_at' => now()->addDays((int) config('phr.native_backup_retention_days', 7)),
        ]);
        $this->audit($backup, 'failed', $category);
    }

    private function audit(PhrNativeBackup $backup, string $outcome, ?string $category): void
    {
        PhrNativeBackupAudit::query()->create([
            'actor_user_id' => $backup->requested_by_user_id,
            'patient_root_id' => $backup->patient_id,
            'operation' => 'backup',
            'schema_version' => $backup->schema_version,
            'archive_sha256' => $backup->archive_sha256,
            'counts_json' => $backup->counts_json,
            'outcome' => $outcome,
            'failure_category' => $category,
        ]);
    }
}
