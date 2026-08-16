<?php

namespace App\Services\PHR\DICOM;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public const DUPLICATE_UPLOAD_MESSAGE = 'Duplicate DICOM study upload was skipped because all image instances already exist for this patient.';

    public function __construct(private readonly DicomMetadataParser $metadataParser) {}

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

        return PhrDicomUpload::create([
            'patient_id' => $patient->id,
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
        ]);
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
        $patient = $upload->patient()->firstOrFail();

        return DB::transaction(function () use ($upload, $patient, $file, $relativePath): array {
            $locked = PhrDicomUpload::query()->lockForUpdate()->findOrFail($upload->id);
            if ($locked->status !== PhrDicomUpload::STATUS_PENDING) {
                throw new HttpException(409, 'Upload session is no longer accepting files.');
            }

            $manifest = $locked->manifest_json ?? [];
            $skippedFiles = $locked->skipped_files_json ?? [];

            $sanitized = $this->sanitizeRelativePath($relativePath, $file->getClientOriginalName(), $locked->total_files);
            $unique = $this->uniqueAgainstManifest($sanitized, $this->stringList($manifest['stored_paths'] ?? []), $skippedFiles);
            $fileSize = (int) $file->getSize();

            $result = $this->classifyAndStore($patient, $locked, $file, $unique);
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
        } catch (Throwable $cleanupError) {
            Log::warning('phr.dicom.cleanup_delete_prefix_failed', [
                'upload_id' => $upload->id,
                'prefix' => $upload->r2_prefix,
                'error' => $cleanupError->getMessage(),
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

        if ($this->isAuxiliaryFile($relativePath)) {
            return $this->skipResult('auxiliary_file');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return $this->skipResult('missing_temp_file');
        }

        $parsed = $this->metadataParser->parse($realPath);
        if (! $parsed['is_dicom']) {
            return $this->skipResult('not_dicom');
        }

        if ($parsed['is_image_instance'] && $this->hasImageInstance($patient->id, $parsed['normalized'])) {
            return $this->skipResult('duplicate_sop_instance');
        }

        $storageKey = $this->storageKey($upload->r2_prefix, $relativePath);
        $this->storeFile($realPath, $storageKey);

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
            $file->getClientMimeType() ?: 'application/dicom',
            (int) $file->getSize(),
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
}
