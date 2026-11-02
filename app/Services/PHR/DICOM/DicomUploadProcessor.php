<?php

namespace App\Services\PHR\DICOM;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Documents\UploadUrlSigner;
use App\Services\PHR\Storage\BlobStorageQuota;
use App\Support\Logging\SafeLog;
use App\Support\Storage\LocalObjectFile;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

class DicomUploadProcessor
{
    /**
     * @var array<int, string>
     */
    private const AUXILIARY_EXTENSIONS = [
        'bat',
        'bmp',
        'cmd',
        'com',
        'config',
        'css',
        'db',
        'dll',
        'doc',
        'docx',
        'exe',
        'exml',
        'gif',
        'htm',
        'html',
        'ico',
        'inf',
        'ini',
        'jpg',
        'jpeg',
        'js',
        'lnk',
        'msi',
        'pdf',
        'png',
        'rtf',
        'std',
        'txt',
        'url',
        'xml',
    ];

    /**
     * @var array<int, string>
     */
    private const AUXILIARY_BASENAMES = [
        'thumbs.db',
        'desktop.ini',
        '.ds_store',
    ];

    public const DISK = 'phr_dicom';

    /** Each file POSTed to the application. */
    public const string TRANSPORT_POST = 'post';

    /** Each file PUT straight to the installation's object store with a signed URL. */
    public const string TRANSPORT_S3 = 's3';

    /** What a directly sent file is signed as, and what one with no type of its own is recorded as. */
    private const string DIRECT_CONTENT_TYPE = 'application/dicom';

    public const DUPLICATE_UPLOAD_MESSAGE = 'Duplicate DICOM study upload was skipped because all image instances already exist for this patient.';

    /** Minutes a signed file URL lives. The browser asks for each file as it gets to it. */
    private const int FILE_URL_MINUTES = 15;

    /** How long after signing a browser may still confirm a file it sent in time. */
    private const int CONFIRM_MINUTES = 240;

    /** What S3 and R2 accept in one PUT, and copy in one CopyObject. */
    private const int MAX_SINGLE_PUT_BYTES = 5 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly DicomMetadataParser $metadataParser,
        private readonly ?PhrPatientArtifactWriteGuard $artifactWriteGuard = null,
        private readonly ?UploadUrlSigner $signer = null,
    ) {}

    /** How the browser sends this installation's files. */
    public function transport(): string
    {
        return $this->signer()->directUploadsEnabled() ? self::TRANSPORT_S3 : self::TRANSPORT_POST;
    }

    /** The largest file the browser may send on this installation's transport. */
    public function maxFileBytes(): int
    {
        return $this->signer()->directUploadsEnabled()
            ? min(DicomUploadLimits::maxDirectFileBytes(), self::MAX_SINGLE_PUT_BYTES)
            : DicomUploadLimits::maxMultipartFileBytes();
    }

    /**
     * Open a new upload session and return the persisted row.
     *
     * Used by the per-file upload flow. The row is created in STATUS_PENDING and
     * remains pending until finalizeUpload() is called. Stale pending rows are
     * reclaimed by the phr:dicom:gc command.
     */
    public function openUpload(PhrPatient $patient, int $uploadedByUserId, ?string $rootName): PhrDicomUpload
    {
        $storagePrefix = PhrStorageKey::dicomUpload((int) $patient->id, Str::uuid()->toString());

        return $this->writeGuard()->run((int) $patient->id, fn (PhrPatient $lockedPatient): PhrDicomUpload => PhrDicomUpload::create([
            'patient_id' => $lockedPatient->id,
            'uploaded_by_user_id' => $uploadedByUserId,
            'status' => PhrDicomUpload::STATUS_PENDING,
            'original_root_name' => $rootName,
            'total_files' => 0,
            'stored_files' => 0,
            'skipped_files' => 0,
            'total_bytes' => 0,
            'stored_bytes' => 0,
            'r2_prefix' => $storagePrefix,
            'manifest_json' => [
                'stored_paths' => [],
                'dicomdir_paths' => [],
                'study_uids' => [],
                'series_uids' => [],
                'instance_uids' => [],
            ],
            'skipped_files_json' => [],
        ]));
    }

    /**
     * Process a single uploaded DICOM file against an open upload session.
     *
     * Used by the per-file upload endpoint. Concurrent requests for the same
     * upload session are serialized via a row lock on phr_dicom_uploads so
     * manifest mutations stay consistent without losing updates.
     *
     * @return array{stored: bool, skipped_reason: string|null, relative_path: string, study_id: int|null}
     */
    public function processSingleFile(PhrDicomUpload $upload, UploadedFile $file, ?string $relativePath): array
    {
        return $this->recordFile(
            $upload,
            $relativePath,
            $file->getClientOriginalName(),
            (int) $file->getSize(),
            fn (PhrPatient $patient, PhrDicomUpload $locked, string $unique): array => $this->classifyAndStore($patient, $locked, $file, $unique),
        );
    }

    /**
     * Sign a PUT for one file of a direct upload, with its exact length in the signature, or
     * say why it won't be stored. A file skipped here is recorded as if it had been sent.
     *
     * The bytes go to a staging key outside the upload's prefix, so never a durable key: a
     * signed URL stays usable until it expires, so one pointing at a durable key could
     * replace a stored image.
     *
     * @return array{skipped: array{stored: bool, skipped_reason: string|null, relative_path: string, study_id: int|null}}|array{token: string, url: string, headers: array<string, string>}
     */
    public function authorizeFile(PhrDicomUpload $upload, ?string $relativePath, string $filename, int $byteSize, ?string $contentType): array
    {
        $current = $upload->refresh();
        if ($current->status !== PhrDicomUpload::STATUS_PENDING) {
            throw new HttpException(409, 'Upload session is no longer accepting files.');
        }
        if ($byteSize === 0 || $this->isAuxiliaryFile($this->sanitizeRelativePath($relativePath, $filename, $current->total_files))) {
            $reason = $byteSize === 0 ? 'not_dicom' : 'auxiliary_file';

            return ['skipped' => $this->recordFile(
                $current, $relativePath, $filename, $byteSize,
                fn (): array => $this->skipResult($reason),
            )];
        }
        app(BlobStorageQuota::class)->assertForPatient($current->patient()->firstOrFail(), $byteSize);

        $key = PhrStorageKey::dicomIncoming((int) $current->patient_id, (int) $current->id, Str::uuid()->toString());
        $expires = now()->addMinutes(self::FILE_URL_MINUTES);

        // Recorded under the patient lock, before the URL exists: patient deletion either sees
        // this window and waits for it, or has already happened and this finds no patient.
        $signed = $this->writeGuard()->run((int) $current->patient_id, function () use ($current, $key, $byteSize, $expires): array {
            $locked = PhrDicomUpload::query()->lockForUpdate()->findOrFail($current->id);
            if ($locked->status !== PhrDicomUpload::STATUS_PENDING) {
                throw new HttpException(409, 'Upload session is no longer accepting files.');
            }
            $locked->update(['signed_url_expires_at' => $expires]);

            return $this->signer()->sign(self::DISK, $key, self::DIRECT_CONTENT_TYPE, $byteSize, $expires);
        });

        return [
            ...$signed,
            'token' => Crypt::encryptString((string) json_encode([
                'upload_id' => $current->id,
                'key' => $key,
                'relative_path' => $relativePath,
                'filename' => $filename,
                'bytes' => $byteSize,
                'content_type' => $contentType,
                'expires' => now()->addMinutes(self::CONFIRM_MINUTES)->getTimestamp(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Record a file the browser sent straight to the store. The object must be there at the
     * signed size. Its bytes are read here, because metadata and the SHA-256 come from them,
     * and it reaches its durable key by a server-side copy that only succeeds if it is still
     * the object that was read.
     *
     * @return array{stored: bool, skipped_reason: string|null, relative_path: string, study_id: int|null}
     */
    public function confirmFile(PhrDicomUpload $upload, string $token): array
    {
        $claim = $this->fileClaim($upload, $token);
        $key = (string) $claim['key'];
        $bytes = (int) $claim['bytes'];

        $object = $this->signer()->head(self::DISK, $key);
        if ($object === null) {
            throw new UnprocessableEntityHttpException('The file did not reach storage.');
        }
        if ($object['bytes'] !== $bytes) {
            $this->disk()->delete($key);
            throw new UnprocessableEntityHttpException('The file in storage is not the declared size.');
        }

        $local = LocalObjectFile::open(self::DISK, $key);
        try {
            $result = $this->recordFile(
                $upload,
                is_string($claim['relative_path'] ?? null) ? $claim['relative_path'] : null,
                (string) $claim['filename'],
                $bytes,
                fn (PhrPatient $patient, PhrDicomUpload $locked, string $unique): array => $this->classifyAndPersist(
                    $patient,
                    $locked,
                    $local->path,
                    $bytes,
                    is_string($claim['content_type'] ?? null) && $claim['content_type'] !== '' ? $claim['content_type'] : self::DIRECT_CONTENT_TYPE,
                    $unique,
                    function (string $storageKey) use ($key, $object): void {
                        if (! $this->signer()->copyIfUnchanged(self::DISK, $key, $storageKey, $object['etag'])) {
                            throw new ConflictHttpException('The file in storage changed while it was being recorded.');
                        }
                    },
                ),
            );
        } finally {
            $local->release();
        }

        try {
            $this->disk()->delete($key);
        } catch (Throwable $cleanupError) {
            // With no row: phr:dicom:gc reclaims it once the upload is no longer pending.
            SafeLog::warning('phr.dicom.cleanup_incoming_failed', ['upload_id' => $upload->id, 'exception' => $cleanupError::class]);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function fileClaim(PhrDicomUpload $upload, string $token): array
    {
        try {
            $claim = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new UnprocessableEntityHttpException('The upload token is not valid.');
        }
        if (! is_array($claim) || ($claim['upload_id'] ?? null) !== $upload->id || ($claim['expires'] ?? 0) < now()->getTimestamp()) {
            throw new UnprocessableEntityHttpException('The upload token is not valid.');
        }

        return $claim;
    }

    /**
     * Enter one file into the session under its row lock: its unique path, whatever $classify
     * decides, the manifest and the counters.
     *
     * @param  callable(PhrPatient, PhrDicomUpload, string): array{stored: bool, skipped_reason: string|null, study_id: int|null, file_kind: string|null, normalized: array<string, mixed>|null}  $classify
     * @return array{stored: bool, skipped_reason: string|null, relative_path: string, study_id: int|null}
     */
    private function recordFile(PhrDicomUpload $upload, ?string $relativePath, string $fallbackName, int $fileSize, callable $classify): array
    {
        $patient = $upload->patient()->firstOrFail();

        return DB::transaction(function () use ($upload, $patient, $relativePath, $fallbackName, $fileSize, $classify): array {
            $locked = PhrDicomUpload::query()->lockForUpdate()->findOrFail($upload->id);
            if ($locked->status !== PhrDicomUpload::STATUS_PENDING) {
                throw new HttpException(409, 'Upload session is no longer accepting files.');
            }

            $manifest = $locked->manifest_json ?? [];
            $skippedFiles = $locked->skipped_files_json ?? [];

            $sanitized = $this->sanitizeRelativePath($relativePath, $fallbackName, $locked->total_files);
            $unique = $this->uniqueAgainstManifest($sanitized, $this->stringList($manifest['stored_paths'] ?? []), $skippedFiles);

            $result = $classify($patient, $locked, $unique);
            $manifest = $this->applyManifest($manifest, $result, $unique);
            if ($result['skipped_reason'] !== null) {
                $skippedFiles[] = $this->skipEntry($unique, $result['skipped_reason']);
            }

            $locked->update([
                'total_files' => $locked->total_files + 1,
                'stored_files' => $locked->stored_files + ($result['stored'] ? 1 : 0),
                'skipped_files' => $locked->skipped_files + ($result['stored'] ? 0 : 1),
                'total_bytes' => $locked->total_bytes + $fileSize,
                'stored_bytes' => $locked->stored_bytes + ($result['stored'] ? $fileSize : 0),
                'manifest_json' => $this->uniqueManifest($manifest),
                'skipped_files_json' => $skippedFiles,
            ]);

            return [
                'stored' => $result['stored'],
                'skipped_reason' => $result['skipped_reason'],
                'relative_path' => $unique,
                'study_id' => $result['study_id'],
            ];
        });
    }

    public function temporaryViewerUrl(PhrDicomFile $file): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $file->r2_key,
            now()->addMinutes($this->viewerUrlTtlMinutes()),
            [
                'ResponseContentType' => 'application/dicom',
                'ResponseContentDisposition' => 'inline; filename="'.$this->safeResponseFilename($file->original_filename).'"',
            ],
        );
    }

    public function instanceDownloadUrl(PhrDicomInstance $instance, int $patientId): string
    {
        if ($this->shouldUseDirectSignedViewerUrls()) {
            return $this->temporaryViewerUrl($instance->file);
        }

        return url("/api/phr/patients/{$patientId}/dicom/instances/{$instance->id}/file");
    }

    /**
     * Whether viewer URLs should be presigned object-store URLs rather than app routes.
     *
     * Gated on the disk actually being an object store, not just on the config flag: the
     * local driver has no temporaryUrl() and throws, so enabling the flag while
     * PHR_DICOM_DISK_DRIVER=local would 500 every image in the viewer rather than fail
     * once at boot. Falling back to the proxy route is always correct — it is what the
     * flag disabled does anyway — so this degrades instead of breaking.
     */
    public function shouldUseDirectSignedViewerUrls(): bool
    {
        return (bool) config('phr.dicom_viewer_direct_signed_urls', false)
            && $this->usesObjectStore();
    }

    /**
     * Whether the phr_dicom disk is backed by an object store that can presign URLs.
     */
    public function usesObjectStore(): bool
    {
        return config('filesystems.disks.'.self::DISK.'.driver') === 's3';
    }

    /**
     * Finalize an open upload session.
     */
    public function finalizeUpload(PhrDicomUpload $upload): PhrDicomUpload
    {
        $upload->refresh();

        if ($this->uploadHasNewImageInstances($upload)) {
            $upload->update([
                'status' => PhrDicomUpload::STATUS_PROCESSED,
                'error_message' => null,
            ]);

            return $upload->refresh();
        }

        if ($this->uploadContainsDuplicateImagePayload($upload)) {
            $this->failUpload($upload, self::DUPLICATE_UPLOAD_MESSAGE);

            return $upload->refresh();
        }

        $message = 'No DICOM image instances were uploaded. The session contained only non-image DICOM files, skipped files, or files that failed before reaching the server.';
        $this->failUpload($upload, $message);

        throw new HttpException(422, $message);
    }

    public function isDuplicateUploadDiscard(PhrDicomUpload $upload): bool
    {
        return $upload->status === PhrDicomUpload::STATUS_FAILED
            && $upload->error_message === self::DUPLICATE_UPLOAD_MESSAGE;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string|null>  $relativePaths
     */
    public function process(PhrPatient $patient, int $uploadedByUserId, array $files, array $relativePaths, ?string $rootName): PhrDicomUpload
    {
        $upload = $this->openUpload($patient, $uploadedByUserId, $rootName);

        try {
            foreach ($files as $index => $file) {
                $this->processSingleFile($upload, $file, $relativePaths[$index] ?? null);
            }

            return $this->finalizeUpload($upload)->load(['files.instance', 'studies.series.instances.file']);
        } catch (Throwable $caught) {
            $this->failUpload($upload, $caught->getMessage());
            throw $caught;
        }
    }

    /**
     * Mark an upload as failed and reclaim everything it persisted.
     *
     * Used by:
     * - the rollback path in process() when a file in the loop throws
     * - the phr:dicom:gc artisan command when a pending upload times out
     *
     * Cleanup is best-effort: storage and DB errors are logged but the upload
     * row is still transitioned to STATUS_FAILED so the caller can surface it.
     */
    public function failUpload(PhrDicomUpload $upload, string $reason): void
    {
        $failureAttributes = [
            'status' => PhrDicomUpload::STATUS_FAILED,
            'error_message' => Str::limit($reason, 1000),
        ];

        $upload->update($failureAttributes);
        $upload->refresh();

        $disk = $this->disk();

        try {
            $disk->deleteDirectory($upload->r2_prefix);
            if ($this->signer()->directUploadsEnabled()) {
                $disk->deleteDirectory(PhrStorageKey::dicomIncomingPrefix((int) $upload->patient_id, (int) $upload->id));
            }
        } catch (Throwable $cleanupError) {
            // Best-effort storage cleanup; the upload row still transitions to
            // STATUS_FAILED below regardless. `r2_prefix` embeds the owning
            // patient id (see PhrStorageKey::dicomUpload()), so it - and the
            // raw exception message, which PR #152 proved can carry SQL
            // bindings - never go into the log context, only the upload id
            // and exception class.
            SafeLog::warning('phr.dicom.cleanup_delete_prefix_failed', [
                'upload_id' => $upload->id,
                'exception' => $cleanupError::class,
            ]);
        }

        // phr_dicom_instances and phr_dicom_files cascade on upload delete in
        // the schema, but we keep the upload row around for audit, so cascade
        // child rows explicitly. Empty studies/series created by this failed
        // upload are also removed so users do not see phantom imaging rows.
        PhrDicomInstance::query()->where('upload_id', $upload->id)->delete();
        PhrDicomFile::query()->where('upload_id', $upload->id)->delete();
        PhrDicomSeries::query()
            ->where('patient_id', $upload->patient_id)
            ->whereDoesntHave('instances')
            ->delete();
        PhrDicomStudy::query()
            ->where('patient_id', $upload->patient_id)
            ->where('upload_id', $upload->id)
            ->whereDoesntHave('instances')
            ->delete();

        $upload->update($failureAttributes);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    private function uploadHasNewImageInstances(PhrDicomUpload $upload): bool
    {
        return PhrDicomInstance::query()->where('upload_id', $upload->id)->exists();
    }

    private function uploadContainsDuplicateImagePayload(PhrDicomUpload $upload): bool
    {
        foreach ($upload->skipped_files_json ?? [] as $skippedFile) {
            if (($skippedFile['reason'] ?? null) === 'duplicate_sop_instance') {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify a single uploaded file and persist it if it is a stored DICOM.
     *
     * @return array{stored: bool, skipped_reason: string|null, study_id: int|null, file_kind: string|null, normalized: array<string, mixed>|null}
     */
    private function classifyAndStore(PhrPatient $patient, PhrDicomUpload $upload, UploadedFile $file, string $relativePath): array
    {
        if (! $file->isValid()) {
            return $this->skipResult('upload_error');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false && ! $this->isAuxiliaryFile($relativePath)) {
            return $this->skipResult('missing_temp_file');
        }

        return $this->classifyAndPersist(
            $patient,
            $upload,
            (string) $realPath,
            (int) $file->getSize(),
            $file->getClientMimeType() ?: self::DIRECT_CONTENT_TYPE,
            $relativePath,
            fn (string $storageKey) => $this->storeFile((string) $realPath, $storageKey),
        );
    }

    /**
     * Classify a local copy of a file, have $store put it at its durable key if it is a
     * stored DICOM, and record it.
     *
     * @param  callable(string): void  $store
     * @return array{stored: bool, skipped_reason: string|null, study_id: int|null, file_kind: string|null, normalized: array<string, mixed>|null}
     */
    private function classifyAndPersist(PhrPatient $patient, PhrDicomUpload $upload, string $realPath, int $fileSize, string $mimeType, string $relativePath, callable $store): array
    {
        if ($this->isAuxiliaryFile($relativePath)) {
            return $this->skipResult('auxiliary_file');
        }

        $parsed = $this->metadataParser->parse($realPath);
        if (! $parsed['is_dicom']) {
            return $this->skipResult('not_dicom');
        }

        if ($parsed['is_image_instance'] && $this->hasImageInstance($patient->id, $parsed['normalized'])) {
            return $this->skipResult('duplicate_sop_instance');
        }

        $storageKey = $this->storageKey($upload->r2_prefix, $relativePath);
        app(BlobStorageQuota::class)->assertForPatient($patient, $fileSize);
        $store($storageKey);

        $sha256 = hash_file('sha256', $realPath);
        if ($sha256 === false) {
            throw new RuntimeException("Unable to hash DICOM file [{$relativePath}].");
        }

        return $this->persistStoredDicomObject(
            $patient,
            $upload,
            $storageKey,
            $relativePath,
            basename($relativePath),
            $mimeType,
            $fileSize,
            $sha256,
            $parsed,
        );
    }

    /**
     * @param  array{
     *     is_dicom: bool,
     *     has_preamble: bool,
     *     metadata: array<string, mixed>,
     *     normalized: array<string, mixed>,
     *     is_image_instance: bool
     * }  $parsed
     * @return array{stored: bool, skipped_reason: string|null, study_id: int|null, file_kind: string|null, normalized: array<string, mixed>|null}
     */
    private function persistStoredDicomObject(PhrPatient $patient, PhrDicomUpload $upload, string $storageKey, string $relativePath, string $originalFilename, string $mimeType, int $fileSizeBytes, string $sha256, array $parsed): array
    {
        $fileKind = $this->isDicomdirPath($relativePath) ? PhrDicomFile::KIND_DICOMDIR : PhrDicomFile::KIND_DICOM;
        $dicomFile = PhrDicomFile::create([
            'patient_id' => $patient->id,
            'upload_id' => $upload->id,
            'file_kind' => $fileKind,
            'r2_key' => $storageKey,
            'original_relative_path' => $relativePath,
            'original_path_hash' => hash('sha256', $relativePath),
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'file_size_bytes' => $fileSizeBytes,
            'sha256' => $sha256,
            'metadata_json' => $parsed['metadata'],
        ]);

        $studyId = null;
        if ($parsed['is_image_instance']) {
            $studyId = $this->upsertImageInstance($patient, $upload, $dicomFile, $parsed['metadata'], $parsed['normalized']);
        }

        return [
            'stored' => true,
            'skipped_reason' => null,
            'study_id' => $studyId,
            'file_kind' => $fileKind,
            'normalized' => $parsed['is_image_instance'] ? $parsed['normalized'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{stored: bool, skipped_reason: string|null, study_id: int|null, file_kind: string|null, normalized: array<string, mixed>|null}  $result
     * @return array<string, mixed>
     */
    private function applyManifest(array $manifest, array $result, string $relativePath): array
    {
        if (! $result['stored']) {
            return $manifest;
        }

        $manifest['stored_paths'][] = $relativePath;
        if ($result['file_kind'] === PhrDicomFile::KIND_DICOMDIR) {
            $manifest['dicomdir_paths'][] = $relativePath;
        }

        if ($result['normalized'] !== null) {
            $manifest['study_uids'][] = $result['normalized']['study_instance_uid'];
            $manifest['series_uids'][] = $result['normalized']['series_instance_uid'];
            $manifest['instance_uids'][] = $result['normalized']['sop_instance_uid'];
        }

        return $manifest;
    }

    /**
     * @return array{stored: bool, skipped_reason: string, study_id: null, file_kind: null, normalized: null}
     */
    private function skipResult(string $reason): array
    {
        return [
            'stored' => false,
            'skipped_reason' => $reason,
            'study_id' => null,
            'file_kind' => null,
            'normalized' => null,
        ];
    }

    /**
     * @param  list<string>  $storedPaths
     * @param  list<array{path: string, reason: string}>  $skippedEntries
     */
    private function uniqueAgainstManifest(string $relativePath, array $storedPaths, array $skippedEntries): string
    {
        $taken = array_flip(array_merge(
            $storedPaths,
            array_map(static fn (array $entry): string => $entry['path'], $skippedEntries),
        ));

        if (! isset($taken[$relativePath])) {
            return $relativePath;
        }

        $directory = trim(dirname($relativePath), '.');
        $filename = basename($relativePath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = $extension === ''
            ? $filename
            : substr($filename, 0, -(strlen($extension) + 1));

        for ($suffix = 2; $suffix < 1_000_000; $suffix++) {
            $candidateName = $extension === ''
                ? sprintf('%s-%d', $basename, $suffix)
                : sprintf('%s-%d.%s', $basename, $suffix, $extension);
            $candidate = $directory === '' || $directory === '/'
                ? $candidateName
                : $directory.'/'.$candidateName;

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        throw new RuntimeException("Unable to find unique relative path for [{$relativePath}].");
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $normalized
     */
    private function upsertImageInstance(PhrPatient $patient, PhrDicomUpload $upload, PhrDicomFile $file, array $metadata, array $normalized): ?int
    {
        $studyUid = (string) $normalized['study_instance_uid'];
        $seriesUid = (string) $normalized['series_instance_uid'];
        $sopUid = (string) $normalized['sop_instance_uid'];
        $modality = $this->nullableString($normalized['modality'] ?? null);

        if ($this->hasImageInstance($patient->id, $normalized)) {
            return null;
        }

        $existingStudy = PhrDicomStudy::query()
            ->where('patient_id', $patient->id)
            ->where('study_instance_uid', $studyUid)
            ->first();

        // upload_id is set only on the original creation; re-uploads of the
        // same study_instance_uid must not overwrite the originating upload.
        $studyAttributes = [
            'study_date' => $normalized['study_date'],
            'study_time' => $normalized['study_time'],
            'accession_number' => $normalized['accession_number'],
            'description' => $normalized['study_description'],
            'modalities' => $this->mergeModalities($existingStudy?->modalities, $modality),
            'metadata_json' => $metadata,
        ];

        if ($existingStudy === null) {
            $studyAttributes['upload_id'] = $upload->id;
        }

        $study = PhrDicomStudy::updateOrCreate(
            [
                'patient_id' => $patient->id,
                'study_instance_uid' => $studyUid,
            ],
            $studyAttributes,
        );

        $series = PhrDicomSeries::updateOrCreate(
            [
                'study_id' => $study->id,
                'series_instance_uid' => $seriesUid,
            ],
            [
                'patient_id' => $patient->id,
                'modality' => $modality,
                'series_number' => $normalized['series_number'],
                'description' => $normalized['series_description'],
                'body_part' => $normalized['body_part'],
                'metadata_json' => $metadata,
            ],
        );

        PhrDicomInstance::create([
            'patient_id' => $patient->id,
            'study_id' => $study->id,
            'series_id' => $series->id,
            'upload_id' => $upload->id,
            'file_id' => $file->id,
            'sop_instance_uid' => $sopUid,
            'sop_class_uid' => $normalized['sop_class_uid'],
            'instance_number' => $normalized['instance_number'],
            'transfer_syntax_uid' => $normalized['transfer_syntax_uid'],
            'rows' => $normalized['rows'],
            'columns' => $normalized['columns'],
            'number_of_frames' => $normalized['number_of_frames'],
            'metadata_json' => $metadata,
        ]);

        return (int) $study->id;
    }

    private function storeFile(string $realPath, string $storageKey): void
    {
        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Unable to open DICOM file [{$realPath}].");
        }

        try {
            if (! Storage::disk(self::DISK)->put($storageKey, $stream)) {
                throw new RuntimeException("Unable to store DICOM object [{$storageKey}].");
            }
        } finally {
            fclose($stream);
        }
    }

    private function viewerUrlTtlMinutes(): int
    {
        return max(1, (int) config('phr.dicom_viewer_url_ttl_minutes', 30));
    }

    private function safeResponseFilename(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], '', $filename);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function hasImageInstance(int $patientId, array $normalized): bool
    {
        $sopInstanceUid = $this->nullableString($normalized['sop_instance_uid'] ?? null);

        return $sopInstanceUid !== null
            && PhrDicomInstance::query()
                ->where('patient_id', $patientId)
                ->where('sop_instance_uid', $sopInstanceUid)
                ->exists();
    }

    private function sanitizeRelativePath(?string $path, ?string $fallbackName, int $index): string
    {
        $relativePath = $this->sanitizePathParts($path);
        if ($relativePath === '') {
            $fallbackPath = $this->sanitizePathParts($fallbackName);
            $relativePath = $fallbackPath === '' ? 'dicom-file-'.($index + 1) : $fallbackPath;
        }

        if (strlen($relativePath) <= 1000) {
            return $relativePath;
        }

        return hash('sha256', $relativePath).'/'.substr(basename($relativePath), 0, 180);
    }

    private function sanitizePathParts(?string $path): string
    {
        $rawPath = trim(str_replace('\\', '/', (string) $path));
        $parts = [];

        foreach (explode('/', $rawPath) as $part) {
            $part = preg_replace('/[\x00-\x1F\x7F]/', '', $part) ?? '';
            $part = trim(str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $part));

            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function isAuxiliaryFile(string $relativePath): bool
    {
        if ($this->isDicomdirPath($relativePath)) {
            return false;
        }

        $basename = strtolower(basename($relativePath));
        if (in_array($basename, self::AUXILIARY_BASENAMES, true)) {
            return true;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, self::AUXILIARY_EXTENSIONS, true)) {
            return true;
        }

        $segments = array_map('strtolower', explode('/', $relativePath));

        return count(array_intersect($segments, ['autorun', 'cdsetup', 'icons', 'setup', 'viewer'])) > 0
            && ! in_array($extension, ['', 'dcm', 'dicom'], true);
    }

    private function isDicomdirPath(string $relativePath): bool
    {
        return strtoupper(basename($relativePath)) === 'DICOMDIR';
    }

    private function storageKey(string $storagePrefix, string $relativePath): string
    {
        return PhrStorageKey::dicomObject($storagePrefix, $relativePath);
    }

    /**
     * @return array{path: string, reason: string}
     */
    private function skipEntry(string $path, string $reason): array
    {
        return [
            'path' => $path,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function uniqueManifest(array $manifest): array
    {
        foreach (['stored_paths', 'dicomdir_paths', 'study_uids', 'series_uids', 'instance_uids'] as $key) {
            $manifest[$key] = array_values(array_unique(array_filter($manifest[$key] ?? [])));
        }

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }

    private function mergeModalities(?string $existingModalities, ?string $modality): ?string
    {
        $modalities = array_filter(explode('\\', (string) $existingModalities));

        if ($modality !== null) {
            $modalities[] = $modality;
        }

        $unique = array_values(array_unique($modalities));

        return $unique === [] ? null : implode('\\', $unique);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function signer(): UploadUrlSigner
    {
        return $this->signer ?? app(UploadUrlSigner::class);
    }

    private function writeGuard(): PhrPatientArtifactWriteGuard
    {
        return $this->artifactWriteGuard ?? app(PhrPatientArtifactWriteGuard::class);
    }
}
