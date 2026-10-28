<?php

namespace App\Services\PHR\Import\Ehi;

use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Models\PhrEhiUpload;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Browser intake of an EHI Tables ZIP (issue #235, PR 1): stage the archive in
 * chunks on the EHI disk, then run EhiIntakeService -- the same service as
 * `phr:ehi:intake` -- from a queued job. Nothing here interprets the package.
 *
 * The staged archive is written only under the patient artifact guard, so patient
 * deletion either sees the row (and deletes the archive) or the write fails on the
 * missing patient. Intake preserves each member as its own blob, so the archive is
 * deleted once intake completes or refuses the package. A failed intake keeps it
 * until expiry so intake can be queued again, and a retry resumes.
 */
final class EhiUploadService
{
    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly PhrPatientArtifactWriteGuard $writeGuard,
        private readonly EhiIntakeService $intake,
    ) {}

    /**
     * @throws EhiUploadRefused
     * @throws AuthorizationException
     */
    public function start(PhrPatient $patient, int $actorUserId, int $size): PhrEhiUpload
    {
        $this->ensureWritable($patient, $actorUserId);
        if ($size < 1 || $size > EhiLimits::fromConfig()->maxContainerBytes()) {
            throw new EhiUploadRefused(EhiUploadRefused::TOO_LARGE, 'the declared size is outside the EHI package limits');
        }

        $diskName = (string) config('phr.ehi_disk');

        return $this->writeGuard->run((int) $patient->id, function (PhrPatient $locked) use ($actorUserId, $size, $diskName): PhrEhiUpload {
            $this->ensureWritable($locked, $actorUserId);
            $path = PhrStorageKey::ehiUpload((int) $locked->id, (string) Str::uuid());
            $disk = Storage::disk($diskName);
            try {
                if (! $disk->put($path, '')) {
                    throw new EhiUploadRefused(EhiUploadRefused::STORAGE_FAILED, 'the staged archive could not be created');
                }

                return PhrEhiUpload::query()->create([
                    'patient_id' => $locked->id,
                    'actor_user_id' => $actorUserId,
                    'storage_disk' => $diskName,
                    'storage_path' => $path,
                    'file_size_bytes' => $size,
                    'uploaded_bytes' => 0,
                    'status' => PhrEhiUpload::STATUS_UPLOADING,
                    'expires_at' => now()->addHours((int) config('phr.ehi_upload_retention_hours')),
                ]);
            } catch (Throwable $failure) {
                $this->deleteQuietly($diskName, $path);

                // Rethrown as is: the guard's transaction retries a deadlock or
                // lock timeout only if it sees the original exception.
                throw $failure;
            }
        });
    }

    /**
     * Append the chunk that starts at `$offset`, which must be exactly the bytes
     * received so far: a retried or out-of-order chunk is refused, never spliced.
     *
     * @throws EhiUploadRefused
     * @throws AuthorizationException
     */
    public function appendChunk(PhrEhiUpload $upload, int $actorUserId, UploadedFile $chunk, int $offset): PhrEhiUpload
    {
        $chunkSize = $chunk->getSize();
        if (! $chunk->isValid() || ! is_int($chunkSize) || $chunkSize < 1 || $chunkSize > (int) config('phr.ehi_upload_chunk_bytes')) {
            throw new EhiUploadRefused(EhiUploadRefused::INVALID_CHUNK, 'the chunk is empty, too large, or invalid');
        }

        return $this->writeGuard->run($upload->patient_id, function (PhrPatient $patient) use ($upload, $actorUserId, $chunk, $chunkSize, $offset): PhrEhiUpload {
            $this->ensureWritable($patient, $actorUserId);
            $current = $this->locked($upload, $patient);
            if ($current->status !== PhrEhiUpload::STATUS_UPLOADING || $current->storage_path === null || $current->expires_at->isPast()) {
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the upload is not accepting bytes');
            }
            if ($offset !== $current->uploaded_bytes || $chunkSize > $current->file_size_bytes - $offset) {
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the chunk does not continue the upload');
            }

            $targetPath = Storage::disk($current->storage_disk)->path($current->storage_path);
            clearstatcache(true, $targetPath);
            $staged = @filesize($targetPath);
            if (is_int($staged) && $staged > $offset) {
                // Bytes of an append whose row update never committed (a rolled
                // back or retried transaction). The row is the authority on what
                // was acknowledged, and its lock is held, so drop them.
                $this->truncate($targetPath, $offset);
                clearstatcache(true, $targetPath);
                $staged = @filesize($targetPath);
            }
            if ($staged !== $offset) {
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the staged archive does not match the bytes received');
            }
            $source = @fopen((string) $chunk->getRealPath(), 'rb');
            $target = @fopen($targetPath, 'ab');
            try {
                if (! is_resource($source) || ! is_resource($target)) {
                    throw new EhiUploadRefused(EhiUploadRefused::STORAGE_FAILED, 'the chunk could not be stored');
                }
                $written = stream_copy_to_stream($source, $target);
                $flushed = fflush($target);
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
            }
            if ($written !== $chunkSize || ! $flushed) {
                // Cut back to the acknowledged length so the client can resend.
                $this->truncate($targetPath, $offset);
                throw new EhiUploadRefused(EhiUploadRefused::STORAGE_FAILED, 'the chunk could not be stored');
            }
            $current->update(['uploaded_bytes' => $offset + $chunkSize]);

            return $current;
        });
    }

    /**
     * Queue intake of a fully uploaded archive, or queue it again after a failure.
     *
     * @throws EhiUploadRefused
     * @throws AuthorizationException
     */
    public function queueIntake(PhrEhiUpload $upload, int $actorUserId): PhrEhiUpload
    {
        $queued = $this->writeGuard->run($upload->patient_id, function (PhrPatient $patient) use ($upload, $actorUserId): PhrEhiUpload {
            $this->ensureWritable($patient, $actorUserId);
            $current = $this->locked($upload, $patient);
            if (! in_array($current->status, [PhrEhiUpload::STATUS_UPLOADING, PhrEhiUpload::STATUS_FAILED], true)) {
                throw new EhiUploadRefused(EhiUploadRefused::NOT_QUEUEABLE, 'intake is already queued, running, or finished');
            }
            if ($current->storage_path === null || $current->expires_at->isPast()) {
                throw new EhiUploadRefused(EhiUploadRefused::EXPIRED, 'the upload expired');
            }
            if ($current->uploaded_bytes !== $current->file_size_bytes) {
                throw new EhiUploadRefused(EhiUploadRefused::INCOMPLETE, 'not every byte has arrived');
            }
            // Whoever queues intake is the actor it runs as.
            $current->update([
                'actor_user_id' => $actorUserId,
                'status' => PhrEhiUpload::STATUS_QUEUED,
                'failure_reason' => null,
                'completed_at' => null,
            ]);

            return $current;
        });

        try {
            IntakeEhiUploadJob::dispatch($queued->id);
        } catch (Throwable) {
            $this->markFailed($queued->id, 'queue_failed');
        }

        return $queued->refresh();
    }

    /**
     * Run intake for a queued upload. Called by the job; every outcome is
     * recorded on the upload as a fixed status and code.
     */
    public function runIntake(int $uploadId): void
    {
        $claimed = PhrEhiUpload::query()
            ->whereKey($uploadId)
            ->whereIn('status', [PhrEhiUpload::STATUS_QUEUED, PhrEhiUpload::STATUS_PROCESSING])
            ->update(['status' => PhrEhiUpload::STATUS_PROCESSING, 'updated_at' => now()]);
        $upload = PhrEhiUpload::query()->find($uploadId);
        if ($claimed === 0 || $upload === null) {
            return;
        }

        $patient = PhrPatient::query()->find($upload->patient_id);
        if ($patient === null || $upload->actor_user_id === null) {
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiIntakeService::FAILURE_ACCESS_REVOKED);

            return;
        }
        if ($patient->archived_at !== null) {
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiUploadRefused::PATIENT_ARCHIVED);

            return;
        }
        if ($upload->storage_path === null || ! Storage::disk($upload->storage_disk)->exists($upload->storage_path)) {
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiUploadRefused::EXPIRED);

            return;
        }

        $source = null;
        try {
            $source = new EhiZipSource(Storage::disk($upload->storage_disk)->path($upload->storage_path));
            $result = $this->intake->intake($source, $patient, $upload->actor_user_id);
        } catch (EhiPackageRejected $rejected) {
            $this->finish($upload, PhrEhiUpload::STATUS_REJECTED, $rejected->reason);

            return;
        } catch (EhiIntakeFailed $failed) {
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, $failed->reason);

            return;
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiIntakeService::FAILURE_ACCESS_REVOKED);

            return;
        } catch (Throwable $failure) {
            // Never log the message: it could carry a storage or source detail.
            Log::warning('EHI upload intake failed unexpectedly.', ['upload_id' => $upload->id, 'exception' => $failure::class]);
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiIntakeService::FAILURE_UNEXPECTED);

            return;
        } finally {
            $source?->close();
        }

        $this->finish($upload, PhrEhiUpload::STATUS_COMPLETED, null, [
            'package_id' => $result->package->id,
            'result_json' => ['replayed' => $result->replayed, 'resumed' => $result->resumed, 'repaired' => $result->repaired],
        ]);
    }

    /** Record a failure without touching the staged archive, so intake can be queued again. */
    public function markFailed(int $uploadId, string $reason): void
    {
        PhrEhiUpload::query()
            ->whereKey($uploadId)
            ->whereIn('status', [PhrEhiUpload::STATUS_QUEUED, PhrEhiUpload::STATUS_PROCESSING])
            ->update(['status' => PhrEhiUpload::STATUS_FAILED, 'failure_reason' => $reason, 'completed_at' => now(), 'updated_at' => now()]);
    }

    /** Delete a staged archive and forget its key. False if the object could not be deleted. */
    public function discardArchive(PhrEhiUpload $upload): bool
    {
        if ($upload->storage_path === null) {
            return true;
        }
        try {
            $disk = Storage::disk($upload->storage_disk);
            if ($disk->exists($upload->storage_path) && ! $disk->delete($upload->storage_path)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        $upload->update(['storage_path' => null]);

        return true;
    }

    /**
     * @throws EhiUploadRefused
     * @throws AuthorizationException
     */
    public function ensureWritable(PhrPatient $patient, int $actorUserId): void
    {
        $this->access->ensureCanWrite($patient, $actorUserId);
        if ($patient->archived_at !== null) {
            throw new EhiUploadRefused(EhiUploadRefused::PATIENT_ARCHIVED, 'the patient is archived');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function finish(PhrEhiUpload $upload, string $status, ?string $reason, array $attributes = []): void
    {
        $upload->update([...$attributes, 'status' => $status, 'failure_reason' => $reason, 'completed_at' => now()]);
        if (in_array($status, PhrEhiUpload::TERMINAL_STATUSES, true)) {
            // A failed delete leaves the key on the row for the purge to retry.
            $this->discardArchive($upload);
        }
    }

    private function locked(PhrEhiUpload $upload, PhrPatient $patient): PhrEhiUpload
    {
        return PhrEhiUpload::query()
            ->whereKey($upload->id)
            ->where('patient_id', $patient->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function truncate(string $path, int $length): void
    {
        $handle = @fopen($path, 'r+b');
        if (is_resource($handle)) {
            ftruncate($handle, $length);
            fclose($handle);
        }
    }

    private function deleteQuietly(string $diskName, string $path): void
    {
        try {
            Storage::disk($diskName)->delete($path);
        } catch (Throwable) {
            // Unreferenced; the storage pruner reclaims it.
        }
    }
}
