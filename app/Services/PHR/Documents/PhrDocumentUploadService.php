<?php

namespace App\Services\PHR\Documents;

use App\DataTransferObjects\PHR\DocumentTextData;
use App\DataTransferObjects\PHR\DocumentUploadData;
use App\DataTransferObjects\PHR\DocumentUploadResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Storage\BlobStorageQuota;
use App\Support\PHR\PhrDocumentUploadLimits;
use App\Support\PHR\PhrReviewStatus;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

final readonly class PhrDocumentUploadService
{
    public function __construct(
        private PhrPatientArtifactWriteGuard $artifactWriteGuard,
        private PhrDocumentIdentityDao $identities,
        private PhrDocumentRelations $relations,
    ) {}

    public function upload(PhrPatient $patient, int $actorUserId, DocumentUploadData $data, bool $admitted = false): DocumentUploadResult
    {
        $realPath = $data->file->getRealPath();
        if (! is_string($realPath)) {
            throw new UnprocessableEntityHttpException('The uploaded file could not be read.');
        }

        $hash = hash_file('sha256', $realPath);
        if (! is_string($hash)) {
            throw new UnprocessableEntityHttpException('The uploaded file could not be hashed.');
        }

        // Preserve user-facing metadata exactly as the browser upload did before
        // this shared service existed. PhrStorageKey sanitizes only the path copy.
        $originalName = $data->file->getClientOriginalName() ?: 'document';
        $byteSize = (int) ($data->file->getSize() ?: 0);

        return $this->persist(
            $patient,
            $data->importSource,
            $data->externalId,
            $hash,
            $byteSize,
            $originalName,
            static function (string $storagePath) use ($realPath): bool {
                $stream = fopen($realPath, 'rb');
                if (! is_resource($stream)) {
                    throw new UnprocessableEntityHttpException('The uploaded file could not be opened.');
                }
                try {
                    return Storage::disk(PhrDocument::STORAGE_DISK)->put($storagePath, $stream);
                } finally {
                    self::closeStreamIfStillOpen($stream);
                }
            },
            [
                'uploaded_by_user_id' => $actorUserId,
                'title' => $data->title ?? pathinfo($originalName, PATHINFO_FILENAME),
                'document_type' => $data->documentType,
                'observed_at' => $data->observedAt,
                'mime_type' => PhrDocumentUploadLimits::storedMimeType($data->file->getMimeType() ?: $data->file->getClientMimeType(), $originalName),
                'summary' => $data->summary,
                'source' => $data->source,
                'tags' => $data->tags,
                'review_status' => $data->reviewStatus,
            ],
            $admitted,
        );
    }

    /**
     * documents.create_text (phr#409): store an agent's markdown note as a `.md`
     * object, through the same identity, duplicate, quota and storage path as an
     * upload. The same external_id with the same body is unchanged; a different
     * body conflicts. Its text is the body, and it waits for a person's review.
     */
    public function createText(PhrPatient $patient, int $actorUserId, DocumentTextData $data): DocumentUploadResult
    {
        $body = $data->body;

        return $this->persist(
            $patient,
            $data->importSource,
            $data->externalId,
            hash('sha256', $body),
            strlen($body),
            $data->filename,
            static fn (string $storagePath): bool => Storage::disk(PhrDocument::STORAGE_DISK)->put($storagePath, $body),
            [
                ...$data->note,
                'uploaded_by_user_id' => $actorUserId,
                'title' => $data->title ?? pathinfo($data->filename, PATHINFO_FILENAME),
                'document_type' => $data->documentType,
                'observed_at' => $data->observedAt,
                'mime_type' => PhrDocumentUploadLimits::MARKDOWN,
                'extracted_text' => $body,
                'summary' => $data->summary,
                'source' => 'agent_upload',
                'tags' => $data->tags,
                'review_status' => PhrReviewStatus::PENDING,
            ],
            false,
            $data->relatesTo === null ? null : fn (PhrDocument $document): bool => $this->relations->set($document, $data->relatesTo),
        );
    }

    /**
     * @param  callable(string): bool  $write  publishes the bytes at the storage key
     * @param  array<string, mixed>  $attributes
     * @param  (callable(PhrDocument): bool)|null  $created  runs on a new document, inside the write guard
     */
    private function persist(
        PhrPatient $patient,
        ?string $importSource,
        ?string $externalId,
        string $hash,
        int $byteSize,
        string $originalName,
        callable $write,
        array $attributes,
        bool $admitted,
        ?callable $created = null,
    ): DocumentUploadResult {
        return $this->artifactWriteGuard->run(
            (int) $patient->id,
            function (PhrPatient $lockedPatient) use ($importSource, $externalId, $hash, $byteSize, $originalName, $write, $attributes, $admitted, $created): DocumentUploadResult {
                $existing = $this->identities->find($lockedPatient, $importSource, $externalId);
                if ($existing instanceof PhrDocument) {
                    if ($existing->trashed()
                        || ! is_string($existing->file_hash)
                        || ! hash_equals($existing->file_hash, $hash)
                        || ! $this->hasStoredFile($existing)) {
                        throw new ConflictHttpException('The external document identifier is already in use.');
                    }

                    return new DocumentUploadResult($existing, DocumentUploadResult::UNCHANGED);
                }

                $duplicate = $this->identities->findDuplicate($lockedPatient, $importSource, $hash);
                if ($duplicate instanceof PhrDocument && $this->hasStoredFile($duplicate)) {
                    // Do not report success for a second stable identity unless it
                    // can be durably reserved. A conflict prevents both duplicate
                    // bytes and a later changed upload from appearing idempotent.
                    throw new ConflictHttpException('The document content already exists in this client namespace.');
                }

                if (! $admitted) {
                    app(BlobStorageQuota::class)->assertForPatient($lockedPatient, $byteSize);
                }
                $storagePath = PhrStorageKey::document(
                    (int) $lockedPatient->id,
                    Str::uuid()->toString(),
                    $originalName,
                );

                try {
                    if (! $write($storagePath)) {
                        throw new HttpException(500, 'The uploaded file could not be stored.');
                    }

                    $document = PhrDocument::query()->create([
                        ...$attributes,
                        'patient_id' => $lockedPatient->id,
                        'user_id' => $lockedPatient->owner_user_id,
                        'original_filename' => $originalName,
                        'storage_disk' => PhrDocument::STORAGE_DISK,
                        'storage_path' => $storagePath,
                        'byte_size' => $byteSize,
                        'file_hash' => $hash,
                        'import_source' => $importSource,
                        'external_id' => $externalId,
                        'imported_at' => now(),
                    ]);
                    if ($created !== null) {
                        $created($document);
                    }

                    return new DocumentUploadResult($document, DocumentUploadResult::CREATED);
                } catch (Throwable $exception) {
                    try {
                        Storage::disk(PhrDocument::STORAGE_DISK)->delete($storagePath);
                    } catch (Throwable) {
                        // Preserve the original write failure.
                    }

                    throw $exception;
                }
            },
        );
    }

    private function hasStoredFile(PhrDocument $document): bool
    {
        return $document->storage_disk === PhrDocument::STORAGE_DISK
            && is_string($document->storage_path)
            && Storage::disk(PhrDocument::STORAGE_DISK)->exists($document->storage_path);
    }

    /** Some Flysystem adapters close streams handed to put(). */
    private static function closeStreamIfStillOpen(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
