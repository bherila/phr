<?php

namespace App\Services\PHR\Import;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PhrDocumentImporter
{
    public function __construct(private PhrImportModelUpserter $upserter) {}

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

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Unable to read document path: {$path}");
        }

        try {
            $stored = Storage::disk('phr_documents')->put($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $stored) {
            throw new RuntimeException("Unable to store document at {$storagePath}.");
        }

        return PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'uploaded_by_user_id' => $actorUserId,
            'title' => $attributes['title'] ?? pathinfo($filename, PATHINFO_FILENAME),
            'document_type' => ValueCoercion::normalizeDocumentType($attributes['document_type'] ?? null),
            'observed_at' => ValueCoercion::dateTime($attributes['observed_at'] ?? null),
            'original_filename' => $filename,
            'storage_disk' => 'phr_documents',
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
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeGenAiDocument(PhrPatient $patient, int $actorUserId, GenAiImportJob $job, array $payload): PhrDocument
    {
        $payload = $this->documentPayload($payload);
        $filename = $job->original_filename ?: 'phr-document-'.$job->id;
        $storagePath = $this->documentStoragePath((int) $patient->id, $filename);
        $stream = Storage::disk('s3')->readStream($job->s3_path);
        if ($stream === null) {
            throw new RuntimeException('Unable to read GenAI source file.');
        }

        try {
            Storage::disk('phr_documents')->put($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'uploaded_by_user_id' => $actorUserId,
            'genai_job_id' => $job->id,
            'title' => ValueCoercion::string($payload['title'] ?? null) ?? pathinfo($filename, PATHINFO_FILENAME),
            'document_type' => ValueCoercion::normalizeDocumentType($payload['document_type'] ?? null),
            'observed_at' => ValueCoercion::dateTime($payload['observed_at'] ?? null),
            'original_filename' => $filename,
            'storage_disk' => 'phr_documents',
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
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDocumentFromGenAiResult(PhrDocument $document, GenAiImportJob $job, array $payload): PhrDocument
    {
        $payload = $this->documentPayload($payload);

        $document->update([
            'genai_job_id' => $job->id,
            'title' => ValueCoercion::string($payload['title'] ?? null) ?? $document->title,
            'document_type' => ValueCoercion::normalizeDocumentType($payload['document_type'] ?? $document->document_type),
            'observed_at' => ValueCoercion::dateTime($payload['observed_at'] ?? null) ?? $document->observed_at,
            'extracted_text' => ValueCoercion::string($payload['extracted_text'] ?? $payload['text'] ?? null) ?? $document->extracted_text,
            'summary' => ValueCoercion::string($payload['summary'] ?? null) ?? $document->summary,
            'tags' => ValueCoercion::tags($payload['tags'] ?? null) ?? $document->tags,
        ]);

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null}  $options
     */
    public function createOrUpdateDocument(PhrPatient $patient, int $actorUserId, array $payload, array $options): PhrDocument
    {
        $payload = $this->documentPayload($payload);

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
            'extracted_text' => ValueCoercion::string($payload['extracted_text'] ?? $payload['text'] ?? null),
            'summary' => ValueCoercion::string($payload['summary'] ?? null),
            'source' => ValueCoercion::normalizeDocumentSource($payload['source'] ?? $options['source'] ?? $options['import_source'] ?? null),
            'tags' => ValueCoercion::tags($payload['tags'] ?? null),
            'import_source' => $options['import_source'] ?? ValueCoercion::string($payload['import_source'] ?? null),
            'external_id' => ValueCoercion::externalId($payload, $options),
            'imported_at' => now(),
        ];

        return $this->upserter->upsert(PhrDocument::class, $attributes);
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
}
