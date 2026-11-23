<?php

namespace App\Services\PHR\Import;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Support\PHR\PhrClinicalNote;
use App\Support\PHR\PhrReviewStatus;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PhrDocumentImporter
{
    public function __construct(
        private PhrImportModelUpserter $upserter,
        private PhrPatientArtifactWriteGuard $artifactWriteGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeLocalDocument(PhrPatient $patient, int $actorUserId, string $path, array $attributes = []): PhrDocument
    {
        $attributes = $this->documentPayload($attributes);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Document path is not readable: {$path}");
        }

        $filename = (string) ($attributes['original_filename'] ?? basename($path));
        $storagePath = $this->documentStoragePath((int) $patient->id, $filename);

        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new RuntimeException("Unable to hash document path: {$path}");
        }

        return $this->artifactWriteGuard->run((int) $patient->id, function (PhrPatient $lockedPatient) use ($path, $storagePath, $actorUserId, $attributes, $filename, $sha256): PhrDocument {
            try {
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    throw new RuntimeException("Unable to read document path: {$path}");
                }
                try {
                    $stored = Storage::disk(PhrDocument::STORAGE_DISK)->put($storagePath, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (! $stored) {
                    throw new RuntimeException("Unable to store document at {$storagePath}.");
                }

                return PhrDocument::create([
                    'patient_id' => $lockedPatient->id,
                    'user_id' => $lockedPatient->owner_user_id,
                    'uploaded_by_user_id' => $actorUserId,
                    'title' => $attributes['title'] ?? pathinfo($filename, PATHINFO_FILENAME),
                    'document_type' => ValueCoercion::normalizeDocumentType($attributes['document_type'] ?? null),
                    'observed_at' => ValueCoercion::dateTime($attributes['observed_at'] ?? null),
                    'original_filename' => $filename,
                    'storage_disk' => PhrDocument::STORAGE_DISK,
                    'storage_path' => $storagePath,
                    'mime_type' => $attributes['mime_type'] ?? $this->mimeType($path),
                    'byte_size' => filesize($path) ?: 0,
                    'file_hash' => $sha256,
                    'extracted_text' => $attributes['extracted_text'] ?? null,
                    'summary' => $attributes['summary'] ?? null,
                    'source' => ValueCoercion::normalizeDocumentSource($attributes['source'] ?? $attributes['import_source'] ?? null),
                    'tags' => $attributes['tags'] ?? null,
                    'import_source' => $attributes['import_source'] ?? null,
                    'external_id' => $attributes['external_id'] ?? null,
                    'imported_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                $this->deleteUnpublishedDocument($storagePath);

                throw $exception;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeGenAiDocument(PhrPatient $patient, int $actorUserId, GenAiImportJob $job, array $payload): PhrDocument
    {
        $payload = $this->documentPayload($payload);
        $filename = $job->original_filename ?: 'phr-document-'.$job->id;
        $storagePath = $this->documentStoragePath((int) $patient->id, $filename);

        return $this->artifactWriteGuard->run((int) $patient->id, function (PhrPatient $lockedPatient) use ($job, $storagePath, $actorUserId, $payload, $filename): PhrDocument {
            try {
                $stream = Storage::disk('s3')->readStream($job->s3_path);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to read GenAI source file.');
                }
                try {
                    $stored = Storage::disk(PhrDocument::STORAGE_DISK)->put($storagePath, $stream);
                } finally {
                    fclose($stream);
                }
                if (! $stored) {
                    throw new RuntimeException('Unable to store GenAI document.');
                }

                return PhrDocument::create([
                    'patient_id' => $lockedPatient->id,
                    'user_id' => $lockedPatient->owner_user_id,
                    'uploaded_by_user_id' => $actorUserId,
                    'genai_job_id' => $job->id,
                    'title' => ValueCoercion::string($payload['title'] ?? null) ?? pathinfo($filename, PATHINFO_FILENAME),
                    'document_type' => ValueCoercion::normalizeDocumentType($payload['document_type'] ?? null),
                    'observed_at' => ValueCoercion::dateTime($payload['observed_at'] ?? null),
                    'original_filename' => $filename,
                    'storage_disk' => PhrDocument::STORAGE_DISK,
                    'storage_path' => $storagePath,
                    'mime_type' => $job->mime_type,
                    'byte_size' => $job->file_size_bytes,
                    'file_hash' => $job->file_hash,
                    'extracted_text' => ValueCoercion::string($payload['extracted_text'] ?? $payload['text'] ?? null),
                    'summary' => ValueCoercion::string($payload['summary'] ?? null),
                    'source' => 'genai_import',
                    'tags' => ValueCoercion::tags($payload['tags'] ?? null),
                    'import_source' => 'genai',
                    'external_id' => 'genai-job-'.$job->id,
                    'imported_at' => now(),
                    // Every field here is what the AI read; the document itself
                    // waits for a person, as an agent's upload does.
                    'review_status' => PhrReviewStatus::PENDING,
                ]);
            } catch (\Throwable $exception) {
                $this->deleteUnpublishedDocument($storagePath);

                throw $exception;
            }
        });
    }

    /**
     * A person accepting the AI proposal in the browser reviewed what it changes,
     * so the document keeps its review status. An agent accepting it (imports.review,
     * possibly with its own replacement payload) is not a person's review: with
     * `$reopenReviewOnChange`, a change to the document's content returns it to
     * `pending_review`, as an agent's documents.update does. Linking the job alone
     * is not a change.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateDocumentFromGenAiResult(PhrDocument $document, GenAiImportJob $job, array $payload, bool $reopenReviewOnChange = false): PhrDocument
    {
        $payload = $this->documentPayload($payload);

        $document->fill([
            'genai_job_id' => $job->id,
            'title' => ValueCoercion::string($payload['title'] ?? null) ?? $document->title,
            'document_type' => ValueCoercion::normalizeDocumentType($payload['document_type'] ?? $document->document_type),
            'observed_at' => ValueCoercion::dateTime($payload['observed_at'] ?? null) ?? $document->observed_at,
            'extracted_text' => ValueCoercion::string($payload['extracted_text'] ?? $payload['text'] ?? null) ?? $document->extracted_text,
            'summary' => ValueCoercion::string($payload['summary'] ?? null) ?? $document->summary,
            'tags' => ValueCoercion::tags($payload['tags'] ?? null) ?? $document->tags,
        ]);
        if ($reopenReviewOnChange && $document->isDirty(['title', 'document_type', 'observed_at', 'extracted_text', 'summary', 'tags'])) {
            $document->setAttribute('review_status', PhrReviewStatus::PENDING);
        }
        $document->save();

        return $document->refresh();
    }

    /**
     * With a `review_status` option (an unreviewed import's pending_review), a new
     * document lands in it and an existing one the payload changes returns to it;
     * an existing one the payload leaves as it is keeps the review it has.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null, review_status?: string, content?: array<string, mixed>, stored_file?: array<string, string>, exact_text?: string|null}  $options
     */
    public function createOrUpdateDocument(PhrPatient $patient, int $actorUserId, array $payload, array $options): PhrDocument
    {
        $payload = $this->documentPayload($payload);
        $content = $options['content'] ?? null;
        if (is_array($content)) {
            return $this->createOrUpdateWithContent($patient, $actorUserId, $payload, $options, $content);
        }

        // `storage_disk` and `storage_path` are deliberately absent.
        //
        // This payload is untrusted parsed input — a FHIR bundle or a model's
        // JSON output — and taking a disk and path from it would let a crafted
        // document point the file-streaming endpoints at an arbitrary object on
        // an arbitrary disk, including another patient's stored documents.
        // Bytes only ever reach a PhrDocument through storeLocalDocument() or
        // storeGenAiDocument(), which derive the path server-side from the
        // patient id and a fresh UUID.
        //
        // Omitting the keys (rather than nulling them) matters on the upsert
        // path: an existing row that already has a stored file keeps its
        // location instead of having it overwritten by a re-import. On insert
        // the column default applies and storage_path stays null, which is
        // correct for a metadata-only record such as a FHIR DocumentReference.
        $attributes = [
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'uploaded_by_user_id' => $actorUserId,
            'genai_job_id' => $options['genai_job_id'] ?? null,
            'title' => ValueCoercion::string($payload['title'] ?? null),
            'document_type' => ValueCoercion::normalizeDocumentType($payload['document_type'] ?? null),
            'observed_at' => ValueCoercion::dateTime($payload['observed_at'] ?? null),
            'original_filename' => ValueCoercion::string($payload['original_filename'] ?? null),
            'mime_type' => ValueCoercion::string($payload['mime_type'] ?? null),
            'byte_size' => (int) ($payload['byte_size'] ?? $payload['file_size_bytes'] ?? 0),
            'file_hash' => ValueCoercion::string($payload['file_hash'] ?? $payload['sha256'] ?? null),
            // The text of stored content is the file's own, byte for byte, never trimmed.
            'extracted_text' => array_key_exists('exact_text', $options) ? $options['exact_text'] : ValueCoercion::string($payload['extracted_text'] ?? $payload['text'] ?? null),
            'summary' => ValueCoercion::string($payload['summary'] ?? null),
            'source' => ValueCoercion::normalizeDocumentSource($payload['source'] ?? $options['source'] ?? $options['import_source'] ?? null),
            'tags' => ValueCoercion::tags($payload['tags'] ?? null),
            'import_source' => $options['import_source'] ?? ValueCoercion::string($payload['import_source'] ?? null),
            'external_id' => ValueCoercion::externalId($payload, $options),
            'imported_at' => now(),
            ...$this->noteAttributes($payload),
            ...($options['stored_file'] ?? []),
        ];

        $review = $options['review_status'] ?? null;
        if ($review !== null) {
            $attributes['review_status'] = $review;
        }

        return $this->upserter->upsert(PhrDocument::class, $attributes, $review);
    }

    /**
     * A document whose bytes the source carries inline (a FHIR attachment's data,
     * a C-CDA note's text): they are stored as the document's file through the
     * documents disk, under a key derived server-side, as an upload's are. The
     * same bytes again keep the stored file; different bytes replace it, and the
     * old object is removed once the new row commits.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $content  {bytes: string, filename: string, mime_type: string|null, text: string|null}
     */
    private function createOrUpdateWithContent(PhrPatient $patient, int $actorUserId, array $payload, array $options, array $content): PhrDocument
    {
        $bytes = (string) ($content['bytes'] ?? '');
        $filename = ValueCoercion::string($content['filename'] ?? null) ?? 'document';
        $sha256 = hash('sha256', $bytes);
        $payload = [
            ...$payload,
            'original_filename' => $payload['original_filename'] ?? $filename,
            'mime_type' => $content['mime_type'] ?? $payload['mime_type'] ?? null,
            'byte_size' => strlen($bytes),
            'file_hash' => $sha256,
        ];
        unset($options['content']);
        $options['exact_text'] = is_string($content['text'] ?? null) ? $content['text'] : null;
        $importSource = $options['import_source'] ?? ValueCoercion::string($payload['import_source'] ?? null);
        $externalId = ValueCoercion::externalId($payload, $options);

        return $this->artifactWriteGuard->run((int) $patient->id, function (PhrPatient $lockedPatient) use ($actorUserId, $payload, $options, $bytes, $filename, $sha256, $importSource, $externalId): PhrDocument {
            $existing = $importSource === null || $externalId === null ? null : PhrDocument::query()
                ->where('patient_id', $lockedPatient->id)->where('import_source', $importSource)->where('external_id', $externalId)->first();
            $kept = $existing instanceof PhrDocument
                && $existing->storage_disk === PhrDocument::STORAGE_DISK
                && is_string($existing->storage_path)
                && is_string($existing->file_hash) && hash_equals($existing->file_hash, $sha256)
                && Storage::disk(PhrDocument::STORAGE_DISK)->exists($existing->storage_path);
            if ($kept) {
                return $this->createOrUpdateDocument($lockedPatient, $actorUserId, $payload, $options);
            }

            $storagePath = $this->documentStoragePath((int) $lockedPatient->id, $filename);
            $previous = $existing instanceof PhrDocument ? $existing->storage_path : null;
            try {
                if (! Storage::disk(PhrDocument::STORAGE_DISK)->put($storagePath, $bytes)) {
                    throw new RuntimeException('Unable to store the document content.');
                }
                $document = $this->createOrUpdateDocument($lockedPatient, $actorUserId, $payload, [
                    ...$options,
                    'stored_file' => ['storage_disk' => PhrDocument::STORAGE_DISK, 'storage_path' => $storagePath],
                ]);
            } catch (\Throwable $exception) {
                $this->deleteUnpublishedDocument($storagePath);

                throw $exception;
            }
            if (is_string($previous) && $previous !== $storagePath) {
                // The replaced bytes go only once the row naming the new ones commits.
                DB::afterCommit(fn () => $this->deleteUnpublishedDocument($previous));
            }

            return $document;
        });
    }

    /**
     * Clinical note metadata (phr#409) a payload states. A key the payload does not
     * carry is left out, so a writer that knows nothing of note metadata never
     * clears what a person or another source set.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function noteAttributes(array $payload): array
    {
        $attributes = [];
        foreach (PhrClinicalNote::COLUMNS as $column) {
            if (! array_key_exists($column, $payload)) {
                continue;
            }
            $value = $payload[$column];
            $attributes[$column] = match ($column) {
                'loinc_code' => PhrClinicalNote::loinc($value),
                'authored_at', 'signed_at', 'patient_signed_at' => PhrClinicalNote::moment($value),
                'doc_status' => PhrClinicalNote::docStatus($value),
                'patient_signature_status' => in_array($value, PhrClinicalNote::PATIENT_SIGNATURE_STATUSES, true) ? $value : null,
                default => ($text = ValueCoercion::string($value)) === null ? null : mb_substr($text, 0, 255),
            };
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function documentPayload(array $payload): array
    {
        if (isset($payload['source_document']) && is_array($payload['source_document'])) {
            return [
                ...$payload,
                ...$payload['source_document'],
            ];
        }

        return $payload;
    }

    private function documentStoragePath(int $patientId, string $filename): string
    {
        return PhrStorageKey::document($patientId, Str::uuid()->toString(), $filename);
    }

    private function mimeType(string $path): ?string
    {
        $mimeType = mime_content_type($path);

        return is_string($mimeType) ? $mimeType : null;
    }

    private function deleteUnpublishedDocument(string $storagePath): void
    {
        try {
            Storage::disk(PhrDocument::STORAGE_DISK)->delete($storagePath);
        } catch (\Throwable) {
            // Preserve the original write/transaction failure. The generic
            // storage pruner remains a fallback for an unreachable random key.
        }
    }
}
