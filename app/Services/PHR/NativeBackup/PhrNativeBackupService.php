<?php

namespace App\Services\PHR\NativeBackup;

use App\Jobs\PHR\GeneratePhrNativeBackupJob;
use App\Models\PhrNativeBackup;
use App\Models\PhrNativeBackupAudit;
use App\Models\PhrPatient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PhrNativeBackupService
{
    public function __construct(private readonly PhrNativeArchiveBuilder $archiveBuilder) {}

    public function createQueuedBackup(PhrPatient $patient, int $requestedByUserId): PhrNativeBackup
    {
        $backup = PhrNativeBackup::query()->create([
            'patient_id' => $patient->id,
            'requested_by_user_id' => $requestedByUserId,
            'status' => PhrNativeBackup::STATUS_PENDING,
            'schema_version' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'storage_disk' => 'phr_exports',
            'expires_at' => now()->addDays((int) config('phr.native_backup_retention_days', 7)),
        ]);

        GeneratePhrNativeBackupJob::dispatch($backup->id);

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

            $backup->update([
                'status' => PhrNativeBackup::STATUS_READY,
                'storage_path' => $storagePath,
                'file_size_bytes' => $result->fileSize,
                'archive_sha256' => $result->sha256,
                'counts_json' => $result->counts,
                'failure_category' => null,
                'generated_at' => now(),
            ]);
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

        return $backup->refresh();
    }

    private function markFailed(PhrNativeBackup $backup, string $category): void
    {
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
