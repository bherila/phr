<?php

namespace App\Services\PHR\Documents;

use App\DataTransferObjects\PHR\DocumentUploadData;
use App\DataTransferObjects\PHR\DocumentUploadResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
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
    ) {}

    public function upload(PhrPatient $patient, int $actorUserId, DocumentUploadData $data): DocumentUploadResult
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

        return $this->artifactWriteGuard->run(
            (int) $patient->id,
            function (PhrPatient $lockedPatient) use ($actorUserId, $byteSize, $data, $hash, $originalName, $realPath): DocumentUploadResult {
                $existing = $this->identities->find($lockedPatient, $data);
                if ($existing instanceof PhrDocument) {
                    if ($existing->trashed()
                        || ! is_string($existing->file_hash)
                        || ! hash_equals($existing->file_hash, $hash)
                        || ! $this->hasStoredFile($existing)) {
                        throw new ConflictHttpException('The external document identifier is already in use.');
                    }

                    return new DocumentUploadResult($existing, DocumentUploadResult::UNCHANGED);
                }

                $duplicate = $this->identities->findDuplicate($lockedPatient, $data, $hash);
                if ($duplicate instanceof PhrDocument && $this->hasStoredFile($duplicate)) {
                    // Do not report success for a second stable identity unless it
                    // can be durably reserved. A conflict prevents both duplicate
                    // bytes and a later changed upload from appearing idempotent.
                    throw new ConflictHttpException('The document content already exists in this client namespace.');
                }

                $storagePath = PhrStorageKey::document(
                    (int) $lockedPatient->id,
                    Str::uuid()->toString(),
                    $originalName,
                );

                try {
                    $stream = fopen($realPath, 'rb');
                    if (! is_resource($stream)) {
                        throw new UnprocessableEntityHttpException('The uploaded file could not be opened.');
                    }
                    try {
                        $stored = Storage::disk(PhrDocument::STORAGE_DISK)->put($storagePath, $stream);
                    } finally {
                        $this->closeStreamIfStillOpen($stream);
                    }
                    if (! $stored) {
                        throw new HttpException(500, 'The uploaded file could not be stored.');
                    }

                    $document = PhrDocument::query()->create([
                        'patient_id' => $lockedPatient->id,
                        'user_id' => $lockedPatient->owner_user_id,
                        'uploaded_by_user_id' => $actorUserId,
                        'title' => $data->title ?? pathinfo($originalName, PATHINFO_FILENAME),
                        'document_type' => $data->documentType,
                        'observed_at' => $data->observedAt,
                        'original_filename' => $originalName,
                        'storage_disk' => PhrDocument::STORAGE_DISK,
                        'storage_path' => $storagePath,
                        'mime_type' => $data->file->getMimeType() ?: $data->file->getClientMimeType(),
                        'byte_size' => $byteSize,
                        'file_hash' => $hash,
                        'summary' => $data->summary,
                        'source' => $data->source,
                        'tags' => $data->tags,
                        'import_source' => $data->importSource,
                        'external_id' => $data->externalId,
                        'imported_at' => now(),
                    ]);

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
    private function closeStreamIfStillOpen(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
