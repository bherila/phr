<?php

namespace App\Services\PHR\Documents;

use App\DataTransferObjects\PHR\DocumentUploadData;
use App\Http\Requests\PHR\StorePhrDocumentRequest;
use App\Models\PhrDocument;
use App\Models\PhrDocumentUpload;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * Signed document uploads, for files too large or too binary to pass through a
 * model's context (phr#268). The flow is open, PUT, finalize:
 *
 *  1. open: a session that names the file (kind, filename, content type, size,
 *     optional SHA-256) and returns a short-lived signed URL;
 *  2. PUT: the bytes go to that URL, once. The URL is a bearer capability (the
 *     caller's sandbox rarely holds the OAuth token), so it can do exactly one
 *     thing: stage bytes of the declared type and size for this session. Nothing
 *     is visible, and no document exists, until finalize;
 *  3. finalize: with the OAuth credential, verifies size and hash and creates the
 *     document through the same service as documents.upload.
 *
 * A session that is never finalized expires and its bytes are deleted.
 */
final readonly class SignedDocumentUploadService
{
    /** Minutes the URL lives, and again the window to finalize once the bytes arrived. */
    public const int TTL_MINUTES = 15;

    private const array DOCUMENT_TYPES = [
        'eob' => 'insurance',
        'rx_label' => 'prescription',
        'pharmacy_receipt' => 'other',
        'statement' => 'other',
        'other' => 'other',
    ];

    public function __construct(
        private PhrPatientArtifactWriteGuard $guard,
        private PhrDocumentUploadService $documents,
    ) {}

    /** @param array{kind: string, filename: string, content_type: string, byte_size: int, sha256?: string|null} $declared */
    public function open(PhrPatient $patient, int $actorUserId, string $importSource, array $declared): PhrDocumentUpload
    {
        return PhrDocumentUpload::query()->create([
            'public_id' => (string) Str::ulid(),
            'patient_id' => $patient->id,
            'actor_user_id' => $actorUserId,
            'import_source' => $importSource,
            'kind' => $declared['kind'],
            'filename' => $declared['filename'],
            'content_type' => strtolower($declared['content_type']),
            'byte_size' => $declared['byte_size'],
            'sha256' => isset($declared['sha256']) ? strtolower($declared['sha256']) : null,
            'status' => PhrDocumentUpload::PENDING,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    public function url(PhrDocumentUpload $upload): string
    {
        return URL::temporarySignedRoute('agent-api.v1.document-uploads.receive', $upload->expires_at, ['upload' => $upload->public_id]);
    }

    /**
     * The PUT. Streams the body, so a large file is never held in memory, and
     * refuses anything that does not match what the session declared.
     */
    public function receive(string $publicId, Request $request): PhrDocumentUpload
    {
        $upload = PhrDocumentUpload::query()->where('public_id', $publicId)->firstOrFail();
        $this->refuseUnusable($upload, PhrDocumentUpload::PENDING);

        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ($type !== $upload->content_type) {
            throw new HttpException(415, "This upload accepts {$upload->content_type} only; send it as the Content-Type header.");
        }
        $length = $request->headers->get('Content-Length');
        if (is_numeric($length) && (int) $length !== $upload->byte_size) {
            throw new HttpException((int) $length > $upload->byte_size ? 413 : 422, 'The body is not the declared byte_size.');
        }

        // Single use: exactly one request wins the claim. A request that fails
        // validation below hands it back, so a bad attempt can be retried.
        $claimed = PhrDocumentUpload::query()->whereKey($upload->id)->where('status', PhrDocumentUpload::PENDING)
            ->update(['status' => PhrDocumentUpload::RECEIVING]);
        if ($claimed !== 1) {
            throw new ConflictHttpException('This upload URL has already been used.');
        }

        $staged = null;
        try {
            $buffer = tmpfile();
            if ($buffer === false) {
                throw new HttpException(500, 'The upload could not be buffered.');
            }
            $received = $this->drain($request, $buffer, $upload);
            rewind($buffer);

            $path = PhrStorageKey::documentUploadStaging((int) $upload->patient_id, Str::uuid()->toString());
            $this->guard->run((int) $upload->patient_id, function () use ($upload, $buffer, $path, $received, &$staged): void {
                if (! Storage::disk(PhrDocument::STORAGE_DISK)->writeStream($path, $buffer)) {
                    throw new HttpException(500, 'The uploaded file could not be stored.');
                }
                $staged = $path;
                // Only a session still receiving may become uploaded: a cancel (or expiry) that
                // ran while the body was draining wins, and its bytes are not kept.
                $published = PhrDocumentUpload::query()->whereKey($upload->id)->where('status', PhrDocumentUpload::RECEIVING)->update([
                    'status' => PhrDocumentUpload::UPLOADED,
                    'staging_path' => $path,
                    'received_size' => $received['size'],
                    'received_sha256' => $received['sha256'],
                    'uploaded_at' => now(),
                    'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                ]);
                if ($published !== 1) {
                    throw new ConflictHttpException('The upload was cancelled or expired while it was being received.');
                }
            });
            fclose($buffer);
        } catch (Throwable $exception) {
            if (is_string($staged)) {
                $this->deleteBlob($staged);
            }
            PhrDocumentUpload::query()->whereKey($upload->id)->where('status', PhrDocumentUpload::RECEIVING)
                ->update(['status' => PhrDocumentUpload::PENDING]);

            throw $exception;
        }

        return $upload->refresh();
    }

    /**
     * Verifies the staged bytes, then creates the document. A repeat returns the
     * document the first call created; bytes the client already has as a document
     * resolve to that document instead of a second copy.
     *
     * @return array{upload: PhrDocumentUpload, document: PhrDocument, outcome: string}
     */
    public function finalize(PhrPatient $patient, string $importSource, string $publicId): array
    {
        return DB::transaction(function () use ($patient, $importSource, $publicId): array {
            $upload = $this->session($patient, $importSource, $publicId, lock: true);

            if ($upload->status === PhrDocumentUpload::FINALIZED && $upload->document !== null) {
                return ['upload' => $upload, 'document' => $upload->document, 'outcome' => 'unchanged'];
            }
            $this->refuseUnusable($upload, PhrDocumentUpload::UPLOADED);

            $path = (string) $upload->staging_path;
            $disk = Storage::disk(PhrDocument::STORAGE_DISK);
            if (! $disk->exists($path) || $disk->size($path) !== $upload->byte_size) {
                throw new UnprocessableEntityHttpException('The uploaded file is not the declared byte_size.');
            }
            $hash = $this->hashStored($path);
            if ($hash !== $upload->received_sha256 || ($upload->sha256 !== null && $hash !== $upload->sha256)) {
                throw new UnprocessableEntityHttpException('The uploaded file does not match the declared sha256.');
            }

            $file = new UploadedFile($disk->path($path), $upload->filename, $upload->content_type, null, true);
            Validator::make(['file' => $file], ['file' => (new StorePhrDocumentRequest)->rules()['file']])->validate();

            // The declared type is the agent's word; the bytes must agree with it.
            $sniffed = (string) $file->getMimeType();
            $agrees = $sniffed === $upload->content_type
                || (str_starts_with($upload->content_type, 'text/') && str_starts_with($sniffed, 'text/'))
                || ($upload->content_type === 'image/jpeg' && $sniffed === 'image/jpg');
            if (! $agrees) {
                throw new UnprocessableEntityHttpException('The uploaded file is not the declared content type.');
            }

            $outcome = 'created';
            try {
                $result = $this->documents->upload($patient, (int) $upload->actor_user_id, DocumentUploadData::fromValidated(
                    $file,
                    [
                        'title' => pathinfo($upload->filename, PATHINFO_FILENAME),
                        'document_type' => self::DOCUMENT_TYPES[$upload->kind] ?? 'other',
                        'tags' => [str_replace('_', '-', $upload->kind)],
                    ],
                    source: 'agent_upload',
                    importSource: $upload->import_source,
                    externalId: 'upload:'.$upload->public_id,
                ));
                $document = $result->document;
            } catch (ConflictHttpException $conflict) {
                $document = PhrDocument::query()
                    ->where('patient_id', $patient->id)
                    ->where('import_source', $upload->import_source)
                    ->where('file_hash', $hash)
                    ->first();
                if (! $document instanceof PhrDocument) {
                    throw $conflict;
                }
                $outcome = 'duplicate';
            }

            $upload->forceFill([
                'status' => PhrDocumentUpload::FINALIZED,
                'finalized_at' => now(),
                'document_id' => $document->id,
                'staging_path' => null,
            ])->save();
            $this->deleteBlob($path);

            return ['upload' => $upload, 'document' => $document, 'outcome' => $outcome];
        });
    }

    public function cancel(PhrPatient $patient, string $importSource, string $publicId): PhrDocumentUpload
    {
        return DB::transaction(function () use ($patient, $importSource, $publicId): PhrDocumentUpload {
            $upload = $this->session($patient, $importSource, $publicId, lock: true);
            if ($upload->status === PhrDocumentUpload::FINALIZED) {
                throw new ConflictHttpException('The upload was finalized; it is a document now.');
            }
            if (in_array($upload->status, [PhrDocumentUpload::PENDING, PhrDocumentUpload::RECEIVING, PhrDocumentUpload::UPLOADED], true)) {
                $this->discard($upload, PhrDocumentUpload::CANCELLED);
            }

            return $upload;
        });
    }

    public function session(PhrPatient $patient, string $importSource, string $publicId, bool $lock = false): PhrDocumentUpload
    {
        $query = PhrDocumentUpload::query()
            ->where('patient_id', $patient->id)
            ->where('import_source', $importSource)
            ->where('public_id', $publicId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** Sessions never finalized: their bytes are deleted. Old terminal rows are dropped. */
    public function expireStale(): int
    {
        $expired = 0;
        PhrDocumentUpload::query()
            ->whereIn('status', [PhrDocumentUpload::PENDING, PhrDocumentUpload::RECEIVING, PhrDocumentUpload::UPLOADED])
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (PhrDocumentUpload $upload) use (&$expired): void {
                $this->discard($upload, PhrDocumentUpload::EXPIRED);
                $expired++;
            });

        PhrDocumentUpload::query()
            ->whereIn('status', [PhrDocumentUpload::FINALIZED, PhrDocumentUpload::CANCELLED, PhrDocumentUpload::EXPIRED])
            ->where('updated_at', '<', now()->subDays(7))
            ->delete();

        return $expired;
    }

    /**
     * A session that cannot take this step: expired, cancelled, finalized, or
     * not yet (or no longer) in the state the step needs.
     */
    private function refuseUnusable(PhrDocumentUpload $upload, string $needs): void
    {
        $open = in_array($upload->status, [PhrDocumentUpload::PENDING, PhrDocumentUpload::RECEIVING, PhrDocumentUpload::UPLOADED], true);
        if ($upload->status === PhrDocumentUpload::EXPIRED || ($open && $upload->expires_at->isPast())) {
            if ($open) {
                $this->discard($upload, PhrDocumentUpload::EXPIRED);
            }

            throw new GoneHttpException('The upload expired. Open a new one.');
        }
        if ($upload->status === PhrDocumentUpload::CANCELLED) {
            throw new ConflictHttpException('The upload was cancelled.');
        }
        if ($upload->status === PhrDocumentUpload::FINALIZED) {
            throw new ConflictHttpException('The upload was finalized.');
        }
        if ($needs === PhrDocumentUpload::UPLOADED && $upload->status !== PhrDocumentUpload::UPLOADED) {
            throw new ConflictHttpException('The file has not been uploaded yet; PUT it to the upload URL first.');
        }
        if ($needs === PhrDocumentUpload::PENDING && $upload->status !== PhrDocumentUpload::PENDING) {
            throw new ConflictHttpException('This upload URL has already been used.');
        }
    }

    private function discard(PhrDocumentUpload $upload, string $status): void
    {
        $path = $upload->staging_path;
        $upload->forceFill(['status' => $status, 'staging_path' => null])->save();
        if (is_string($path)) {
            $this->deleteBlob($path);
        }
    }

    private function deleteBlob(string $path): void
    {
        try {
            Storage::disk(PhrDocument::STORAGE_DISK)->delete($path);
        } catch (Throwable) {
            // The row no longer references it, so storage:prune reclaims it.
        }
    }

    /**
     * @param  resource  $buffer
     * @return array{size: int, sha256: string}
     */
    private function drain(Request $request, $buffer, PhrDocumentUpload $upload): array
    {
        $input = $request->getContent(true);
        $context = hash_init('sha256');
        $size = 0;
        while (($chunk = fread($input, 1_048_576)) !== false && $chunk !== '') {
            $size += strlen($chunk);
            if ($size > $upload->byte_size) {
                throw new HttpException(413, 'The body is larger than the declared byte_size.');
            }
            hash_update($context, $chunk);
            fwrite($buffer, $chunk);
        }
        if ($size !== $upload->byte_size) {
            throw new UnprocessableEntityHttpException('The body is not the declared byte_size.');
        }
        $sha256 = hash_final($context);
        if ($upload->sha256 !== null && $sha256 !== $upload->sha256) {
            throw new UnprocessableEntityHttpException('The body does not match the declared sha256.');
        }

        return ['size' => $size, 'sha256' => $sha256];
    }

    private function hashStored(string $path): string
    {
        $stream = Storage::disk(PhrDocument::STORAGE_DISK)->readStream($path);
        if (! is_resource($stream)) {
            throw new UnprocessableEntityHttpException('The uploaded file could not be read.');
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
