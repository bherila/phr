<?php

namespace App\Services\PHR\Import;

use App\DataTransferObjects\PHR\ImportJobMutationResult;
use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Shared browser/agent boundary for staging a document as a structured import. */
final readonly class PhrDocumentProcessingService
{
    public function __construct(
        private PhrPatientArtifactWriteGuard $artifactWriteGuard,
        private PhrImportJobDao $jobs,
    ) {}

    public function create(PhrPatient $patient, int $actorUserId, int $documentId): ImportJobMutationResult
    {
        $newStagingPath = null;

        try {
            $result = $this->artifactWriteGuard->run(
                (int) $patient->id,
                function (PhrPatient $lockedPatient) use ($actorUserId, $documentId, &$newStagingPath): ImportJobMutationResult {
                    $document = PhrDocument::query()
                        ->where('patient_id', $lockedPatient->id)
                        ->findOrFail($documentId);
                    $existing = $this->jobs->findForDocument($lockedPatient, $document);
                    if ($existing instanceof GenAiImportJob) {
                        return $existing->status === 'failed'
                            ? $this->prepareRetry($existing, $document, $newStagingPath)
                            : new ImportJobMutationResult($existing, (int) $document->id, ImportJobMutationResult::UNCHANGED);
                    }
                    if ($document->genai_job_id !== null) {
                        throw new ConflictHttpException('The document references an unavailable import job.');
                    }

                    $this->assertStoredDocument($document);
                    $newStagingPath ??= $this->stagingPath($actorUserId, $document);
                    $this->copyToStaging($document, $newStagingPath);
                    $job = GenAiImportJob::query()->create([
                        'user_id' => $actorUserId,
                        'job_type' => 'phr_document',
                        'file_hash' => $document->file_hash ?? hash('sha256', $newStagingPath),
                        'original_filename' => $document->original_filename ?? 'document',
                        's3_path' => $newStagingPath,
                        'mime_type' => $document->mime_type,
                        'file_size_bytes' => $document->byte_size,
                        'context_json' => json_encode([
                            'patient_id' => $lockedPatient->id,
                            'document_id' => $document->id,
                            'document_type' => $document->document_type,
                            'filename_hint' => $document->original_filename,
                        ], JSON_THROW_ON_ERROR),
                        'status' => 'pending',
                    ]);
                    $document->update(['genai_job_id' => $job->id]);

                    return new ImportJobMutationResult($job, (int) $document->id, ImportJobMutationResult::CREATED);
                },
            );
        } catch (Throwable $exception) {
            $this->deleteStagingFileIfPresent($newStagingPath);

            throw $exception;
        }

        return $this->completeMutation($result, $newStagingPath);
    }

    public function retry(PhrPatient $patient, int $jobId): ImportJobMutationResult
    {
        $newStagingPath = null;

        try {
            $result = $this->artifactWriteGuard->run(
                (int) $patient->id,
                function (PhrPatient $lockedPatient) use ($jobId, &$newStagingPath): ImportJobMutationResult {
                    $job = $this->jobs->find($lockedPatient, $jobId);
                    $document = $job->sourceDocument()->firstOrFail();

                    return $this->prepareRetry($job, $document, $newStagingPath);
                },
            );
        } catch (Throwable $exception) {
            $this->deleteStagingFileIfPresent($newStagingPath);

            throw $exception;
        }

        return $this->completeMutation($result, $newStagingPath);
    }

    private function prepareRetry(
        GenAiImportJob $job,
        PhrDocument $document,
        ?string &$newStagingPath,
    ): ImportJobMutationResult {
        if ($document->trashed()) {
            throw new NotFoundHttpException;
        }
        if (in_array($job->status, ['pending', 'processing', 'queued_tomorrow'], true)) {
            return new ImportJobMutationResult($job, (int) $document->id, ImportJobMutationResult::UNCHANGED);
        }
        if (! $job->canRetry()) {
            throw new ConflictHttpException('The import job cannot be retried.');
        }
        if ($job->results()->where('status', '!=', 'pending_review')->exists()) {
            throw new ConflictHttpException('A reviewed import job cannot be retried.');
        }

        if (! Storage::disk('s3')->exists($job->s3_path)) {
            $this->assertStoredDocument($document);
            $newStagingPath ??= $this->stagingPath((int) $job->user_id, $document);
            $this->copyToStaging($document, $newStagingPath);
        }

        $job->results()->where('status', 'pending_review')->delete();
        $job->update([
            's3_path' => $newStagingPath ?? $job->s3_path,
            'status' => 'pending',
            'error_message' => null,
            'raw_response' => null,
            'scheduled_for' => null,
            'parsed_at' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'processing_tier' => null,
        ]);

        return new ImportJobMutationResult($job->refresh(), (int) $document->id, ImportJobMutationResult::RETRIED);
    }

    private function completeMutation(ImportJobMutationResult $result, ?string $newStagingPath): ImportJobMutationResult
    {
        if (! in_array($result->outcome, [ImportJobMutationResult::CREATED, ImportJobMutationResult::RETRIED], true)) {
            $this->deleteStagingFileIfPresent($newStagingPath);

            return $result;
        }

        $this->dispatch($result->job);

        return $result;
    }

    private function assertStoredDocument(PhrDocument $document): void
    {
        if ($document->storage_disk !== PhrDocument::STORAGE_DISK
            || $document->storage_path === null
            || ! Storage::disk(PhrDocument::STORAGE_DISK)->exists($document->storage_path)) {
            throw new NotFoundHttpException;
        }
    }

    private function stagingPath(int $actorUserId, PhrDocument $document): string
    {
        $filename = PhrStorageKey::safeFilename($document->original_filename ?? 'document', 'document');

        return 'genai-import/'.$actorUserId.'/'.Str::uuid().'/'.$filename;
    }

    private function copyToStaging(PhrDocument $document, string $stagingPath): void
    {
        $stream = Storage::disk(PhrDocument::STORAGE_DISK)->readStream((string) $document->storage_path);
        if (! is_resource($stream)) {
            throw new NotFoundHttpException;
        }

        try {
            $stored = Storage::disk('s3')->put($stagingPath, $stream);
        } finally {
            $this->closeStreamIfStillOpen($stream);
        }
        if (! $stored) {
            throw new HttpException(503, 'GenAI staging storage is not available.');
        }
    }

    private function dispatch(GenAiImportJob $job): void
    {
        try {
            ParseImportJob::dispatch($job->id);
        } catch (Throwable $exception) {
            // The pending-row recovery command will redispatch this job. Keep
            // logs PHI-safe by recording only the job ID and exception class.
            Log::warning('Import job dispatch deferred to queue recovery.', [
                'job_id' => $job->id,
                'exception' => $exception::class,
            ]);
        }
    }

    /** Some Flysystem adapters close streams handed to put(). */
    private function closeStreamIfStillOpen(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private function deleteStagingFile(string $stagingPath): void
    {
        try {
            Storage::disk('s3')->delete($stagingPath);
        } catch (Throwable) {
            // Preserve the original write failure. The storage pruner remains
            // the fallback for an unreachable random staging key.
        }
    }

    private function deleteStagingFileIfPresent(?string $stagingPath): void
    {
        if ($stagingPath !== null) {
            $this->deleteStagingFile($stagingPath);
        }
    }
}
