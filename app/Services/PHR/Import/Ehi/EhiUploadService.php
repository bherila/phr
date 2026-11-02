<?php

namespace App\Services\PHR\Import\Ehi;

use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Models\PhrDocument;
use App\Models\PhrEhiUpload;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Documents\UploadUrlSigner;
use App\Services\PHR\Import\PhrMultiplePatientsInSource;
use App\Services\PHR\Import\PhrMyChartZipImporter;
use App\Services\PHR\Storage\BlobStorageQuota;
use App\Support\Storage\LocalObjectFile;
use App\Support\Storage\MultipartPlan;
use App\Support\Storage\PhrStorageKey;
use App\Support\Storage\StagedChunkAppender;
use App\Support\Storage\StagedUpload;
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
 * missing patient. A chunked upload still open when an installation moved to an object
 * store waits in a local spool (StagedUpload) that the job publishes first; that one write
 * can take too long to hold the lock for, so the job checks afterward instead. Intake
 * preserves each member as its own blob, so the archive is deleted once intake completes
 * or refuses the package. A failed intake keeps it until expiry so intake can be queued
 * again, and a retry resumes.
 *
 * On an installation that chose an object store, the browser sends the archive straight
 * to it as a multipart upload instead (issue #241): this signs each part with its length,
 * then completes the upload and checks the object's size before intake is queued. Intake
 * reads the object the same way, through a local temporary copy.
 *
 * A ZIP intake refuses as not EHI is tried as a MyChart record ZIP, so one upload
 * path serves both kinds of export.
 */
final class EhiUploadService
{
    /** Skipped-file warnings kept on a MyChart upload's result. */
    private const int MAX_WARNINGS = 20;

    /** Minutes a signed part URL lives. The browser asks for each part as it gets to it. */
    private const int PART_URL_MINUTES = 15;

    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly PhrPatientArtifactWriteGuard $writeGuard,
        private readonly EhiIntakeService $intake,
        private readonly PhrMyChartZipImporter $myChart,
        private readonly UploadUrlSigner $signer,
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
            app(BlobStorageQuota::class)->assertForPatient($locked, $size);
            $path = PhrStorageKey::ehiUpload((int) $locked->id, (string) Str::uuid());
            $plan = $this->signer->directUploadsEnabled() ? MultipartPlan::forFile($size, (int) config('phr.ehi_upload_chunk_bytes')) : null;
            $multipartUploadId = null;
            try {
                if ($plan !== null) {
                    $multipartUploadId = $this->signer->createMultipartUpload($diskName, $path, 'application/zip');
                } elseif (! StagedUpload::create($diskName, $path)) {
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
                    'transport' => $plan !== null ? PhrEhiUpload::TRANSPORT_S3 : PhrEhiUpload::TRANSPORT_CHUNKS,
                    'multipart_upload_id' => $multipartUploadId,
                    'part_size_bytes' => $plan?->partBytes,
                    'expires_at' => now()->addHours((int) config('phr.ehi_upload_retention_hours')),
                ]);
            } catch (Throwable $failure) {
                $this->deleteQuietly($diskName, $path);
                if ($multipartUploadId !== null) {
                    $this->abortQuietly($diskName, $path, $multipartUploadId);
                }

                // Rethrown as is: the guard's transaction retries a deadlock or
                // lock timeout only if it sees the original exception.
                throw $failure;
            }
        });
    }

    /**
     * Stage a stored ZIP document as an upload and queue its intake: the path an
     * agent's imports.create takes for an EHI or MyChart export. A repeat returns
     * the document's upload; after a failure it queues intake again, or stages a
     * fresh copy when the failed upload's copy is gone.
     *
     * @return array{0: PhrEhiUpload, 1: bool} the upload, and whether intake was queued now
     *
     * @throws EhiUploadRefused
     * @throws AuthorizationException
     */
    public function stageDocument(PhrPatient $patient, int $actorUserId, PhrDocument $document): array
    {
        $this->ensureWritable($patient, $actorUserId);
        $size = (int) $document->byte_size;
        if ($size < 1 || $size > EhiLimits::fromConfig()->maxContainerBytes()) {
            throw new EhiUploadRefused(EhiUploadRefused::TOO_LARGE, 'the document is outside the EHI package limits');
        }

        $diskName = (string) config('phr.ehi_disk');
        [$upload, $queue] = $this->writeGuard->run((int) $patient->id, function (PhrPatient $locked) use ($actorUserId, $document, $size, $diskName): array {
            $this->ensureWritable($locked, $actorUserId);
            $existing = PhrEhiUpload::query()->where('patient_id', $locked->id)->where('source_document_id', $document->id)->latest('id')->lockForUpdate()->first();
            if ($existing !== null && $existing->status !== PhrEhiUpload::STATUS_FAILED) {
                return [$existing, false];
            }
            // A failed upload is queued again only while its staged copy still
            // exists; otherwise (purged, expired, or the copy lost) a fresh copy
            // is staged below, so a retry neither sticks nor fails the same way.
            if ($existing !== null && $existing->storage_path !== null && $existing->expires_at->isFuture()
                && Storage::disk($existing->storage_disk)->exists($existing->storage_path)) {
                $existing->update(['actor_user_id' => $actorUserId, 'status' => PhrEhiUpload::STATUS_QUEUED, 'failure_reason' => null, 'completed_at' => null]);

                return [$existing, true];
            }

            app(BlobStorageQuota::class)->assertForPatient($locked, $size);
            $path = PhrStorageKey::ehiUpload((int) $locked->id, (string) Str::uuid());
            try {
                $written = $this->linkOrCopy($document, $diskName, $path);
                if (! $written || Storage::disk($diskName)->size($path) !== $size) {
                    throw new EhiUploadRefused(EhiUploadRefused::STORAGE_FAILED, 'the staged copy does not match the document');
                }

                return [PhrEhiUpload::query()->create([
                    'patient_id' => $locked->id,
                    'actor_user_id' => $actorUserId,
                    'source_document_id' => $document->id,
                    'storage_disk' => $diskName,
                    'storage_path' => $path,
                    'file_size_bytes' => $size,
                    'uploaded_bytes' => $size,
                    'status' => PhrEhiUpload::STATUS_QUEUED,
                    'expires_at' => now()->addHours((int) config('phr.ehi_upload_retention_hours')),
                ]), true];
            } catch (Throwable $failure) {
                $this->deleteQuietly($diskName, $path);

                throw $failure;
            }
        });

        if ($queue) {
            try {
                IntakeEhiUploadJob::dispatch($upload->id);
            } catch (Throwable) {
                $this->markFailed($upload->id, 'queue_failed');
            }
        }

        return [$upload->refresh(), $queue];
    }

    /**
     * Put a document's bytes at a staging key. Documents are immutable, so on the
     * same disk a hard link or server-side copy stages a multi-gigabyte export at once,
     * without moving it through PHP while the patient lock is held; removing the staging
     * key later leaves the document intact. Elsewhere the bytes are streamed across.
     */
    private function linkOrCopy(PhrDocument $document, string $diskName, string $path): bool
    {
        $from = Storage::disk(PhrDocument::STORAGE_DISK);
        $to = Storage::disk($diskName);
        if ($diskName === PhrDocument::STORAGE_DISK
            && LocalObjectFile::duplicateWithinDisk($diskName, (string) $document->storage_path, $path)) {
            return true;
        }
        $source = $from->readStream((string) $document->storage_path);
        if (! is_resource($source)) {
            throw new EhiUploadRefused(EhiUploadRefused::STORAGE_FAILED, 'the document could not be read');
        }
        try {
            return $to->writeStream($path, $source);
        } finally {
            fclose($source);
        }
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
            if ($current->status !== PhrEhiUpload::STATUS_UPLOADING || $current->storage_path === null || $current->expires_at->isPast()
                || $current->transport !== PhrEhiUpload::TRANSPORT_CHUNKS) {
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the upload is not accepting bytes');
            }
            if ($offset !== $current->uploaded_bytes || $chunkSize > $current->file_size_bytes - $offset) {
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the chunk does not continue the upload');
            }

            $outcome = StagedUpload::append($current->storage_disk, $current->storage_path, (string) $chunk->getRealPath(), $offset, $chunkSize);
            if ($outcome === StagedChunkAppender::STATE_MISMATCH) {
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the staged archive does not match the bytes received');
            }
            if ($outcome === StagedChunkAppender::STORAGE_FAILED) {
                throw new EhiUploadRefused(EhiUploadRefused::STORAGE_FAILED, 'the chunk could not be stored');
            }
            $current->update(['uploaded_bytes' => $offset + $chunkSize]);

            return $current;
        });
    }

    /**
     * A signed URL for one part of a direct upload, with its exact length in the signature.
     * Asking for a part also says every part before it arrived, which is what the progress
     * shown elsewhere reads; completing checks that against the store.
     *
     * @return array{part_number: int, byte_size: int, url: string, headers: array<string, string>}
     *
     * @throws EhiUploadRefused
     * @throws AuthorizationException
     */
    public function signPart(PhrEhiUpload $upload, int $actorUserId, int $partNumber): array
    {
        return $this->writeGuard->run($upload->patient_id, function (PhrPatient $patient) use ($upload, $actorUserId, $partNumber): array {
            $this->ensureWritable($patient, $actorUserId);
            $current = $this->locked($upload, $patient);
            $plan = $this->openPlan($current);
            if (! $plan->holds($partNumber)) {
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the upload has no such part');
            }
            $expires = now()->addMinutes(self::PART_URL_MINUTES)->min($current->expires_at);
            // Under the patient lock, before the URL exists: patient deletion waits for this.
            $current->update([
                'uploaded_bytes' => max($current->uploaded_bytes, $plan->bytesBefore($partNumber)),
                'part_url_expires_at' => $expires,
            ]);
            $bytes = $plan->bytesOf($partNumber);

            return [
                'part_number' => $partNumber,
                'byte_size' => $bytes,
                ...$this->signer->signPart(
                    $current->storage_disk,
                    (string) $current->storage_path,
                    (string) $current->multipart_upload_id,
                    $partNumber,
                    $bytes,
                    $expires,
                ),
            ];
        });
    }

    /**
     * Finish a direct upload: every part must be at the store with the length the plan says,
     * and the finished object must be the declared size. The store's own list is the authority
     * on what it holds, so this needs nothing from the browser, and a person whose last request
     * failed can finish after a reload. $etags, when the browser still has them, must agree with
     * that list too. A repeat after this succeeded has nothing left to do.
     *
     * @param  array<int, string>  $etags  by part number; empty to go by the store's list alone
     *
     * @throws EhiUploadRefused
     * @throws AuthorizationException
     */
    public function completeParts(PhrEhiUpload $upload, int $actorUserId, array $etags = []): void
    {
        $this->writeGuard->run($upload->patient_id, function (PhrPatient $patient) use ($upload, $actorUserId, $etags): void {
            $this->ensureWritable($patient, $actorUserId);
            $current = $this->locked($upload, $patient);
            if ($current->transport !== PhrEhiUpload::TRANSPORT_S3 || $current->multipart_upload_id === null) {
                return;
            }
            $plan = $this->openPlan($current);
            $disk = $current->storage_disk;
            $path = (string) $current->storage_path;
            $uploadId = $current->multipart_upload_id;
            try {
                $held = array_intersect_key($this->signer->listParts($disk, $path, $uploadId), array_flip(range(1, $plan->partCount())));
                foreach (range(1, $plan->partCount()) as $partNumber) {
                    $part = $held[$partNumber] ?? null;
                    if ($part === null || $part['bytes'] !== $plan->bytesOf($partNumber)
                        || ($etags !== [] && ! hash_equals($part['etag'], UploadUrlSigner::normalizeEtag($etags[$partNumber] ?? '')))) {
                        throw new EhiUploadRefused(EhiUploadRefused::INCOMPLETE, 'not every part arrived');
                    }
                }
                $this->signer->completeMultipartUpload($disk, $path, $uploadId, array_map(static fn (array $part): string => $part['etag'], $held));
            } catch (EhiUploadRefused $refused) {
                throw $refused;
            } catch (Throwable $failure) {
                // The store may have finished and only the answer been lost.
                Log::warning('EHI multipart upload could not be completed.', ['upload_id' => $current->id, 'exception' => $failure::class]);
                if ($this->storedBytes($disk, $path) !== $current->file_size_bytes) {
                    throw new EhiUploadRefused(EhiUploadRefused::STORAGE_FAILED, 'the upload could not be completed');
                }
            }
            if ($this->storedBytes($disk, $path) !== $current->file_size_bytes) {
                $this->deleteQuietly($disk, $path);
                throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the finished object is not the declared size');
            }
            // A finished upload takes no more parts, so its URLs can land nothing.
            $current->update(['multipart_upload_id' => null, 'part_url_expires_at' => null, 'uploaded_bytes' => $current->file_size_bytes]);
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
        if (! $this->publishSpool($upload)) {
            return;
        }
        if ($upload->storage_path === null || ! Storage::disk($upload->storage_disk)->exists($upload->storage_path)) {
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiUploadRefused::EXPIRED);

            return;
        }

        try {
            $archive = LocalObjectFile::open($upload->storage_disk, $upload->storage_path);
        } catch (Throwable $failure) {
            Log::warning('EHI upload could not be read from storage.', ['upload_id' => $upload->id, 'exception' => $failure::class]);
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiIntakeFailed::STORAGE_FAILED);

            return;
        }
        try {
            $this->intakeArchive($upload, $patient, $archive->path);
        } finally {
            $archive->release();
        }
    }

    /**
     * Turn a spooled upload into the object intake reads. This runs outside the patient
     * lock, because it can move gigabytes, so the row is checked afterward: if patient
     * deletion or the purge let go of the upload meanwhile, nothing would ever delete the
     * object written here.
     *
     * @return bool whether intake should carry on
     */
    private function publishSpool(PhrEhiUpload $upload): bool
    {
        if ($upload->storage_path === null) {
            return true;
        }
        try {
            if (! StagedUpload::publish($upload->storage_disk, $upload->storage_path, $upload->file_size_bytes)) {
                return true;
            }
        } catch (Throwable $failure) {
            Log::warning('EHI upload could not be published to storage.', ['upload_id' => $upload->id, 'exception' => $failure::class]);
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiIntakeFailed::STORAGE_FAILED);

            return false;
        }

        try {
            $current = $this->writeGuard->run($upload->patient_id, fn (): bool => PhrEhiUpload::query()
                ->whereKey($upload->id)->where('storage_path', $upload->storage_path)->exists());
        } catch (ModelNotFoundException) {
            $current = false;
        }
        if (! $current) {
            $this->deleteQuietly($upload->storage_disk, $upload->storage_path);
        }

        return $current;
    }

    private function intakeArchive(PhrEhiUpload $upload, PhrPatient $patient, string $path): void
    {
        $actorUserId = (int) $upload->actor_user_id;
        $source = null;
        try {
            $source = new EhiZipSource($path);
            $result = $this->intake->intake($source, $patient, $actorUserId);
        } catch (EhiPackageRejected $rejected) {
            if ($rejected->reason !== EhiPackageRejected::NOT_EHI) {
                $this->finish($upload, PhrEhiUpload::STATUS_REJECTED, $rejected->reason);

                return;
            }
            $result = null;
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

        if ($result === null) {
            // Not an EHI Tables export; the source is closed, so read it again as MyChart.
            $this->importMyChart($upload, $patient, $path);

            return;
        }

        $this->finish($upload, PhrEhiUpload::STATUS_COMPLETED, null, [
            'format' => PhrEhiUpload::FORMAT_EHI,
            'package_id' => $result->package->id,
            'result_json' => ['replayed' => $result->replayed, 'resumed' => $result->resumed, 'repaired' => $result->repaired],
        ]);
    }

    /**
     * A ZIP that is not an EHI Tables export may be a MyChart record ZIP: its FHIR
     * and C-CDA files are imported directly by the importer `phr:import:mychart-zip`
     * uses, landing pending review. A ZIP with neither is refused.
     */
    private function importMyChart(PhrEhiUpload $upload, PhrPatient $patient, string $path): void
    {
        if (! $this->myChart->recognizes($path)) {
            $this->finish($upload, PhrEhiUpload::STATUS_REJECTED, EhiUploadRefused::UNRECOGNIZED_EXPORT);

            return;
        }
        try {
            $this->access->ensureCanWrite($patient, (int) $upload->actor_user_id);
            $result = $this->myChart->importFile($path, $patient, (int) $upload->actor_user_id, $this->sourceDocument($upload, $patient));
        } catch (PhrMultiplePatientsInSource) {
            $this->finish($upload, PhrEhiUpload::STATUS_REJECTED, EhiPackageRejected::MULTIPLE_PATIENTS);

            return;
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiIntakeService::FAILURE_ACCESS_REVOKED);

            return;
        } catch (Throwable $failure) {
            Log::warning('MyChart upload import failed unexpectedly.', ['upload_id' => $upload->id, 'exception' => $failure::class]);
            $this->finish($upload, PhrEhiUpload::STATUS_FAILED, EhiIntakeService::FAILURE_UNEXPECTED);

            return;
        }

        $this->finish($upload, PhrEhiUpload::STATUS_COMPLETED, null, [
            'format' => PhrEhiUpload::FORMAT_MYCHART,
            'result_json' => [
                'created' => $result->created,
                'updated' => $result->updated,
                'unchanged' => $result->unchanged,
                'skipped' => $result->skipped,
                // Each names a file in the person's own ZIP and why it was skipped.
                'warnings' => array_slice($result->warnings, 0, self::MAX_WARNINGS),
                'warnings_omitted' => max(0, count($result->warnings) - self::MAX_WARNINGS),
            ],
        ]);
    }

    /**
     * The stored document an upload was staged from (an agent's imports.create),
     * still this patient's; a browser upload has none, and rows it writes are
     * left unlinked.
     */
    private function sourceDocument(PhrEhiUpload $upload, PhrPatient $patient): ?PhrDocument
    {
        if ($upload->source_document_id === null) {
            return null;
        }

        return PhrDocument::query()->where('patient_id', $patient->id)->find($upload->source_document_id);
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
            if ($upload->multipart_upload_id !== null) {
                $this->signer->abortMultipartUpload($upload->storage_disk, $upload->storage_path, $upload->multipart_upload_id);
                $upload->update(['multipart_upload_id' => null, 'part_url_expires_at' => null]);
            }
            $disk = Storage::disk($upload->storage_disk);
            if (! StagedUpload::discard($upload->storage_path)
                || ($disk->exists($upload->storage_path) && ! $disk->delete($upload->storage_path))) {
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

    private function storedBytes(string $disk, string $path): ?int
    {
        return $this->signer->head($disk, $path)['bytes'] ?? null;
    }

    /**
     * How much of an upload has arrived. For a direct upload still open, that is what the store
     * holds at the lengths the plan says, counted from the first part, which reaches the file's
     * size only when finishing would succeed. The row's own count only says which parts were
     * signed. This only reads, and falls back to that count if the store doesn't answer.
     */
    public function bytesReceived(PhrEhiUpload $upload): int
    {
        if ($upload->transport !== PhrEhiUpload::TRANSPORT_S3 || $upload->status !== PhrEhiUpload::STATUS_UPLOADING
            || $upload->multipart_upload_id === null || $upload->storage_path === null) {
            return $upload->uploaded_bytes;
        }
        try {
            $held = $this->signer->listParts($upload->storage_disk, $upload->storage_path, $upload->multipart_upload_id);
        } catch (Throwable $failure) {
            Log::warning('EHI multipart upload could not be listed.', ['upload_id' => $upload->id, 'exception' => $failure::class]);

            return $upload->uploaded_bytes;
        }
        $plan = new MultipartPlan($upload->file_size_bytes, (int) $upload->part_size_bytes);
        $received = 0;
        foreach (range(1, $plan->partCount()) as $partNumber) {
            if (($held[$partNumber]['bytes'] ?? null) !== $plan->bytesOf($partNumber)) {
                break;
            }
            $received += $plan->bytesOf($partNumber);
        }

        return $received;
    }

    /** The plan of a direct upload that is still taking parts. */
    private function openPlan(PhrEhiUpload $current): MultipartPlan
    {
        if ($current->status !== PhrEhiUpload::STATUS_UPLOADING || $current->storage_path === null
            || $current->transport !== PhrEhiUpload::TRANSPORT_S3 || $current->multipart_upload_id === null) {
            throw new EhiUploadRefused(EhiUploadRefused::UPLOAD_STATE, 'the upload is not accepting parts');
        }
        if ($current->expires_at->isPast()) {
            throw new EhiUploadRefused(EhiUploadRefused::EXPIRED, 'the upload expired');
        }

        return new MultipartPlan($current->file_size_bytes, (int) $current->part_size_bytes);
    }

    private function abortQuietly(string $diskName, string $path, string $uploadId): void
    {
        try {
            $this->signer->abortMultipartUpload($diskName, $path, $uploadId);
        } catch (Throwable) {
            // phr:ehi-uploads:purge aborts an upload no row knows, once it is old enough.
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

    private function deleteQuietly(string $diskName, string $path): void
    {
        try {
            StagedUpload::discard($path);
            Storage::disk($diskName)->delete($path);
        } catch (Throwable) {
            // Unreferenced; the storage pruner reclaims it.
        }
    }
}
