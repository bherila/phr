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
    public function __construct(private readonly PhrNativeArchiveBuilder $archiveBuilder) {}

    public function createQueuedBackup(PhrPatient $patient, int $requestedByUserId): PhrNativeBackup
    {
        // Lock the aggregate root so concurrent requests cannot both observe an empty
        // backup set and enqueue duplicate multi-gigabyte work. A pending/processing
        // archive, or a still-downloadable ready archive, is already a usable response.
        [$backup, $created] = DB::transaction(function () use ($patient, $requestedByUserId): array {
            $lockedPatient = PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $existing = PhrNativeBackup::query()
                ->where('patient_id', $lockedPatient->id)
                ->where(function ($query): void {
                    $query->whereIn('status', [
                        PhrNativeBackup::STATUS_PENDING,
                        PhrNativeBackup::STATUS_PROCESSING,
                    ])->orWhere(function ($ready): void {
                        $ready->where('status', PhrNativeBackup::STATUS_READY)
                            ->where(function ($unexpired): void {
                                $unexpired->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            });
                    });
                })
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            return [PhrNativeBackup::query()->create([
                'patient_id' => $lockedPatient->id,
                'requested_by_user_id' => $requestedByUserId,
                'status' => PhrNativeBackup::STATUS_PENDING,
                'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
                'storage_disk' => 'phr_exports',
                'expires_at' => now()->addDays((int) config('phr.native_backup_retention_days', 7)),
            ]), true];
        });

        if ($created) {
            GeneratePhrNativeBackupJob::dispatch($backup->id);
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
                ]);

                return $current;
            });
            if ($readyBackup === null) {
                Storage::disk($backup->storage_disk)->delete($storagePath);
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

    /**
     * Delete every native archive before its patient row cascades the tracking rows.
     * A failed disk deletion aborts aggregate deletion so bytes never become untracked.
     */
    public function deleteForPatient(PhrPatient $patient): void
    {
        PhrNativeBackup::query()
            ->where('patient_id', $patient->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (PhrNativeBackup $backup): void {
                if ($backup->storage_path !== null
                    && ! Storage::disk($backup->storage_disk)->delete($backup->storage_path)) {
                    throw new RuntimeException('Native backup storage cleanup failed.');
                }

                $backup->delete();
            });
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
