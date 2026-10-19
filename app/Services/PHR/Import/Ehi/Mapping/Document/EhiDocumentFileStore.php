<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Models\PhrDocument;
use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackageMember;
use App\Services\PHR\Import\Ehi\EhiBlobVerifier;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Byte handling for EHI document proposals.
 *
 * Preserved EHI blobs are only ever read: a document gets its own copy under the
 * ordinary document key (`PhrStorageKey::document`), so deleting or replacing the
 * document can never touch the evidence. Every read is checked against the
 * manifest's size and sha256, and every copy is re-verified after it is written;
 * a copy that fails is deleted before the error propagates. Callers publish
 * inside PhrPatientArtifactWriteGuard.
 */
final readonly class EhiDocumentFileStore
{
    public function __construct(private EhiBlobVerifier $verifier) {}

    /**
     * The member's bytes, if they are present, match the manifest, and fit the
     * cap; null otherwise. Used for RTF conversion at preview.
     */
    public function readVerified(PhrEhiPackageMember $member, int $maxBytes): ?string
    {
        $blob = $this->blobOf($member);
        if ($blob === null || $member->byte_size > $maxBytes) {
            return null;
        }
        try {
            $bytes = Storage::disk($blob->storage_disk)->get($blob->storage_path);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($bytes) || strlen($bytes) !== $member->byte_size || ! hash_equals($member->sha256, hash('sha256', $bytes))) {
            return null;
        }

        return $bytes;
    }

    /**
     * Copy a member's preserved bytes to a new document object.
     *
     * @return array{path: string, sha256: string, bytes: int}
     */
    public function copyMember(int $patientId, PhrEhiPackageMember $member, string $expectedSha256, string $filename): array
    {
        $blob = $this->blobOf($member);
        if ($blob === null || ! hash_equals($member->sha256, $expectedSha256)
            || $this->verifier->inspect($blob->storage_disk, $blob->storage_path, $member->sha256, $member->byte_size) !== EhiBlobVerifier::OK) {
            throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::EVIDENCE_UNAVAILABLE, 'the linked member is missing or no longer matches its manifest');
        }

        $path = PhrStorageKey::document($patientId, Str::uuid()->toString(), $filename);
        try {
            $stream = Storage::disk($blob->storage_disk)->readStream($blob->storage_path);
            if (! is_resource($stream)) {
                throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::EVIDENCE_UNAVAILABLE, 'the linked member could not be opened');
            }
            try {
                $stored = Storage::disk(PhrDocument::STORAGE_DISK)->writeStream($path, $stream);
            } finally {
                $this->closeIfOpen($stream);
            }
            if ($stored !== true
                || $this->verifier->inspect(PhrDocument::STORAGE_DISK, $path, $member->sha256, $member->byte_size) !== EhiBlobVerifier::OK) {
                throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::STORAGE_FAILED, 'the document copy could not be stored and verified');
            }
        } catch (Throwable $exception) {
            $this->delete($path);

            throw $exception;
        }

        return ['path' => $path, 'sha256' => $member->sha256, 'bytes' => $member->byte_size];
    }

    /**
     * Store derived plain text as a new document object.
     *
     * @return array{path: string, sha256: string, bytes: int}
     */
    public function storeText(int $patientId, string $text, string $filename): array
    {
        $path = PhrStorageKey::document($patientId, Str::uuid()->toString(), $filename);
        $sha256 = hash('sha256', $text);
        try {
            $stored = Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $text);
            if ($stored !== true
                || $this->verifier->inspect(PhrDocument::STORAGE_DISK, $path, $sha256, strlen($text)) !== EhiBlobVerifier::OK) {
                throw new EhiDocumentApplyRefused(EhiDocumentApplyRefused::STORAGE_FAILED, 'the derived text could not be stored and verified');
            }
        } catch (Throwable $exception) {
            $this->delete($path);

            throw $exception;
        }

        return ['path' => $path, 'sha256' => $sha256, 'bytes' => strlen($text)];
    }

    /** Best-effort removal of an object this store created; never an EHI blob. */
    public function delete(string $path): void
    {
        try {
            Storage::disk(PhrDocument::STORAGE_DISK)->delete($path);
        } catch (Throwable) {
            // Preserve the original failure.
        }
    }

    /** Some Flysystem adapters close streams handed to them. */
    private function closeIfOpen(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /** The member's blob, only on the configured EHI disk and for the same patient. */
    private function blobOf(PhrEhiPackageMember $member): ?PhrEhiBlob
    {
        $blob = PhrEhiBlob::query()
            ->where('patient_id', $member->patient_id)
            ->whereKey($member->blob_id)
            ->first();
        if (! $blob instanceof PhrEhiBlob
            || $blob->storage_disk !== (string) config('phr.ehi_disk')
            || ! hash_equals($blob->sha256, $member->sha256)
            || $blob->byte_size !== $member->byte_size) {
            return null;
        }

        return $blob;
    }
}
