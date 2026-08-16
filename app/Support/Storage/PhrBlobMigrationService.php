<?php

namespace App\Support\Storage;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomUpload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Copies legacy PHR blobs into canonical keys without deleting rollback bytes.
 *
 * Destination UUIDs use a fixed UUIDv5 namespace plus table identity. This is
 * deliberate: migration must be resumable without adding mutable mapping state,
 * while the name input contains only an internal table and row id (never a patient
 * value, filename, object key, or clinical field). New application writes continue
 * to use random UUIDs through PhrStorageKey.
 */
final class PhrBlobMigrationService
{
    public const array ARTIFACTS = [
        'documents',
        'dicom-originals',
        'dicom-derived',
        'exports',
        'native-backups',
    ];

    public const array DISKS = ['phr_documents', 'phr_dicom', 'phr_exports'];

    private const string UUID_NAMESPACE = 'c49e9b22-a4ad-5a87-a68f-2f0149be6770';

    /**
     * @param  callable(array{artifact: string, table: string, id: int, status: string}): void|null  $reporter
     */
    public function run(
        bool $apply,
        ?string $disk = null,
        ?string $artifact = null,
        ?int $patientId = null,
        ?callable $reporter = null,
    ): PhrBlobMigrationSummary {
        $summary = new PhrBlobMigrationSummary;
        $emit = function (string $artifactName, string $table, int $id, string $status, int $bytes = 0) use ($summary, $reporter): void {
            $summary->record($status, $bytes);
            if ($reporter !== null) {
                $reporter([
                    'artifact' => $artifactName,
                    'table' => $table,
                    'id' => $id,
                    'status' => $status,
                ]);
            }
        };

        if ($this->selected('documents', 'phr_documents', $disk, $artifact)) {
            $this->migrateDocuments($apply, $patientId, $emit);
        }
        if ($this->selected('dicom-originals', 'phr_dicom', $disk, $artifact)) {
            $this->migrateDicomFiles($apply, $patientId, false, $emit);
            $this->migrateDicomUploadPrefixes($apply, $patientId, $emit);
        }
        if ($this->selected('dicom-derived', 'phr_dicom', $disk, $artifact)) {
            $this->migrateDicomFiles($apply, $patientId, true, $emit);
        }
        if ($this->selected('exports', 'phr_exports', $disk, $artifact)) {
            $this->migrateExports($apply, $patientId, $emit);
        }
        if ($this->selected('native-backups', 'phr_exports', $disk, $artifact)) {
            $this->migrateNativeBackups($apply, $patientId, $emit);
        }

        return $summary;
    }

    /** @param callable(string, string, int, string, int): void $emit */
    private function migrateDocuments(bool $apply, ?int $patientId, callable $emit): void
    {
        $query = DB::table('phr_documents')->whereNotNull('storage_path')->orderBy('id');
        $this->scopePatient($query, $patientId);

        $query->chunkById(100, function ($rows) use ($apply, $emit): void {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $patientId = (int) $row->patient_id;
                if ((string) $row->storage_disk !== 'phr_documents') {
                    $emit('documents', 'phr_documents', $id, 'invalid_reference', 0);

                    continue;
                }

                $source = (string) $row->storage_path;
                if ($this->isCanonical($source, $patientId, 'documents')) {
                    $result = $this->verifyCanonicalReference(
                        $apply,
                        $patientId,
                        'phr_documents',
                        'phr_documents',
                        $id,
                        'storage_path',
                        $source,
                    );
                    $emit('documents', 'phr_documents', $id, $result['status'], $result['bytes']);

                    continue;
                }
                if ($this->isCanonicalNamespace($source) || ! $this->legacyPatientMatches($source, $patientId)) {
                    $emit('documents', 'phr_documents', $id, 'invalid_reference', 0);

                    continue;
                }

                $destination = PhrStorageKey::document(
                    $patientId,
                    $this->stableUuid('phr_documents', $id),
                    is_string($row->original_filename) ? $row->original_filename : 'document',
                );
                $result = $this->migrateReference(
                    $apply,
                    'documents',
                    'phr_documents',
                    'phr_documents',
                    $id,
                    'storage_path',
                    $source,
                    $destination,
                    is_numeric($row->byte_size) ? (int) $row->byte_size : null,
                    is_string($row->file_hash) ? $row->file_hash : null,
                );
                $emit('documents', 'phr_documents', $id, $result['status'], $result['bytes']);
            }
        });
    }

    /** @param callable(string, string, int, string, int): void $emit */
    private function migrateExports(bool $apply, ?int $patientId, callable $emit): void
    {
        $query = DB::table('phr_exports')->whereNotNull('storage_path')->orderBy('id');
        $this->scopePatient($query, $patientId);

        $query->chunkById(100, function ($rows) use ($apply, $emit): void {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $patientId = (int) $row->patient_id;
                if ((string) $row->storage_disk !== 'phr_exports') {
                    $emit('exports', 'phr_exports', $id, 'invalid_reference', 0);

                    continue;
                }

                $source = (string) $row->storage_path;
                if ($this->isCanonical($source, $patientId, 'exports')) {
                    $result = $this->verifyCanonicalReference(
                        $apply,
                        $patientId,
                        'phr_exports',
                        'phr_exports',
                        $id,
                        'storage_path',
                        $source,
                    );
                    $emit('exports', 'phr_exports', $id, $result['status'], $result['bytes']);

                    continue;
                }
                if ($this->isCanonicalNamespace($source) || ! $this->legacyPatientMatches($source, $patientId)) {
                    $emit('exports', 'phr_exports', $id, 'invalid_reference', 0);

                    continue;
                }

                $destination = PhrStorageKey::export(
                    $patientId,
                    $this->stableUuid('phr_exports', $id),
                    is_string($row->filename) ? $row->filename : 'export',
                );
                $result = $this->migrateReference(
                    $apply,
                    'exports',
                    'phr_exports',
                    'phr_exports',
                    $id,
                    'storage_path',
                    $source,
                    $destination,
                    is_numeric($row->file_size_bytes) ? (int) $row->file_size_bytes : null,
                    null,
                );
                $emit('exports', 'phr_exports', $id, $result['status'], $result['bytes']);
            }
        });
    }

    /** @param callable(string, string, int, string, int): void $emit */
    private function migrateNativeBackups(bool $apply, ?int $patientId, callable $emit): void
    {
        $query = DB::table('phr_native_backups')->whereNotNull('storage_path')->orderBy('id');
        $this->scopePatient($query, $patientId);

        $query->chunkById(100, function ($rows) use ($apply, $emit): void {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $patientId = (int) $row->patient_id;
                if ((string) $row->storage_disk !== 'phr_exports') {
                    $emit('native-backups', 'phr_native_backups', $id, 'invalid_reference', 0);

                    continue;
                }

                $source = (string) $row->storage_path;
                if ($this->isCanonical($source, $patientId, 'exports')) {
                    $result = $this->verifyCanonicalReference(
                        $apply,
                        $patientId,
                        'phr_exports',
                        'phr_native_backups',
                        $id,
                        'storage_path',
                        $source,
                    );
                    $emit('native-backups', 'phr_native_backups', $id, $result['status'], $result['bytes']);

                    continue;
                }
                if ($this->isCanonicalNamespace($source) || ! $this->legacyPatientMatches($source, $patientId)) {
                    $emit('native-backups', 'phr_native_backups', $id, 'invalid_reference', 0);

                    continue;
                }

                $destination = PhrStorageKey::nativeBackup(
                    $patientId,
                    $this->stableUuid('phr_native_backups', $id),
                );
                $result = $this->migrateReference(
                    $apply,
                    'native-backups',
                    'phr_exports',
                    'phr_native_backups',
                    $id,
                    'storage_path',
                    $source,
                    $destination,
                    is_numeric($row->file_size_bytes) ? (int) $row->file_size_bytes : null,
                    is_string($row->archive_sha256) ? $row->archive_sha256 : null,
                );
                $emit('native-backups', 'phr_native_backups', $id, $result['status'], $result['bytes']);
            }
        });
    }

    /** @param callable(string, string, int, string, int): void $emit */
    private function migrateDicomFiles(bool $apply, ?int $patientId, bool $derived, callable $emit): void
    {
        $kind = $derived ? PhrDicomFile::KIND_DERIVED_VOLUME : PhrDicomFile::KIND_DICOM;
        $query = PhrDicomFile::query()
            ->with('upload')
            ->when(
                $derived,
                fn (Builder $builder) => $builder->where('file_kind', $kind),
                fn (Builder $builder) => $builder->whereIn('file_kind', [PhrDicomFile::KIND_DICOM, PhrDicomFile::KIND_DICOMDIR]),
            )
            ->when($patientId !== null, fn (Builder $builder) => $builder->where('patient_id', $patientId))
            ->orderBy('id');

        $query->chunkById(100, function ($files) use ($apply, $derived, $emit): void {
            foreach ($files as $file) {
                $artifact = $derived ? 'dicom-derived' : 'dicom-originals';
                $id = (int) $file->id;
                $patientId = (int) $file->patient_id;
                $source = (string) $file->r2_key;
                $canonicalArea = $derived ? 'imaging/dicom/derived' : 'imaging/dicom/uploads';
                if ($this->isCanonical($source, $patientId, $canonicalArea)) {
                    $result = $this->verifyCanonicalReference(
                        $apply,
                        $patientId,
                        'phr_dicom',
                        'phr_dicom_files',
                        $id,
                        'r2_key',
                        $source,
                    );
                    $emit($artifact, 'phr_dicom_files', $id, $result['status'], $result['bytes']);

                    continue;
                }
                if ($this->isCanonicalNamespace($source) || ! $this->legacyPatientMatches($source, $patientId)) {
                    $emit($artifact, 'phr_dicom_files', $id, 'invalid_reference', 0);

                    continue;
                }
                if (! $derived && $file->upload->status === PhrDicomUpload::STATUS_PENDING) {
                    $emit($artifact, 'phr_dicom_files', $id, 'active_upload', 0);

                    continue;
                }

                $destination = $derived
                    ? $this->derivedDestination($file)
                    : $this->originalDicomDestination($file);
                if ($destination === null) {
                    $emit($artifact, 'phr_dicom_files', $id, 'invalid_reference', 0);

                    continue;
                }

                $result = $this->migrateReference(
                    $apply,
                    $artifact,
                    'phr_dicom',
                    'phr_dicom_files',
                    $id,
                    'r2_key',
                    $source,
                    $destination,
                    (int) $file->file_size_bytes,
                    $file->sha256,
                );
                $emit($artifact, 'phr_dicom_files', $id, $result['status'], $result['bytes']);
            }
        });
    }

    private function originalDicomDestination(PhrDicomFile $file): ?string
    {
        $upload = $file->upload;
        if ((int) $upload->patient_id !== (int) $file->patient_id) {
            return null;
        }

        try {
            return PhrStorageKey::dicomObject(
                PhrStorageKey::dicomUpload(
                    (int) $file->patient_id,
                    $this->stableUuid('phr_dicom_uploads', (int) $upload->id),
                ),
                $file->original_relative_path,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function derivedDestination(PhrDicomFile $file): ?string
    {
        $metadata = $file->metadata_json ?? [];
        $seriesId = $metadata['series_id'] ?? null;
        $pipelineVersion = $metadata['pipeline_version'] ?? null;
        if (! is_numeric($seriesId) || ! is_numeric($pipelineVersion)) {
            return null;
        }
        if (! PhrDicomSeries::query()->whereKey((int) $seriesId)->where('patient_id', $file->patient_id)->exists()) {
            return null;
        }

        try {
            return PhrStorageKey::dicomDerivedSeries(
                (int) $file->patient_id,
                (int) $seriesId,
                (int) $pipelineVersion,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** @param callable(string, string, int, string, int): void $emit */
    private function migrateDicomUploadPrefixes(bool $apply, ?int $patientId, callable $emit): void
    {
        $query = PhrDicomUpload::query()
            ->when($patientId !== null, fn (Builder $builder) => $builder->where('patient_id', $patientId))
            ->orderBy('id');

        $query->chunkById(100, function ($uploads) use ($apply, $emit): void {
            foreach ($uploads as $upload) {
                $id = (int) $upload->id;
                $patientId = (int) $upload->patient_id;
                $source = (string) $upload->r2_prefix;
                if ($this->isCanonical($source, $patientId, 'imaging/dicom/uploads')) {
                    $emit('dicom-originals', 'phr_dicom_uploads', $id, 'already_canonical', 0);

                    continue;
                }
                if ($this->isCanonicalNamespace($source)) {
                    $emit('dicom-originals', 'phr_dicom_uploads', $id, 'invalid_reference', 0);

                    continue;
                }
                if ($upload->status === PhrDicomUpload::STATUS_PENDING) {
                    $emit('dicom-originals', 'phr_dicom_uploads', $id, 'active_upload', 0);

                    continue;
                }

                $destination = PhrStorageKey::dicomUpload(
                    $patientId,
                    $this->stableUuid('phr_dicom_uploads', $id),
                );
                if (! $apply) {
                    $emit('dicom-originals', 'phr_dicom_uploads', $id, 'planned', 0);

                    continue;
                }

                $legacyChildrenRemain = $upload->files()
                    ->whereIn('file_kind', [PhrDicomFile::KIND_DICOM, PhrDicomFile::KIND_DICOMDIR])
                    ->where('r2_key', 'not like', $destination.'/%')
                    ->exists();
                if ($legacyChildrenRemain) {
                    $emit('dicom-originals', 'phr_dicom_uploads', $id, 'blocked_children', 0);

                    continue;
                }

                $updated = DB::table('phr_dicom_uploads')
                    ->where('id', $id)
                    ->where('r2_prefix', $source)
                    ->update(['r2_prefix' => $destination, 'updated_at' => now()]);
                $emit('dicom-originals', 'phr_dicom_uploads', $id, $updated === 1 ? 'migrated' : 'stale_reference', 0);
            }
        });
    }

    /**
     * @return array{status: string, bytes: int}
     */
    private function migrateReference(
        bool $apply,
        string $artifact,
        string $diskName,
        string $table,
        int $id,
        string $column,
        string $source,
        string $destination,
        ?int $expectedSize,
        ?string $expectedHash,
    ): array {
        if (! $this->validStoredKey($source) || ! $this->validStoredKey($destination) || $source === $destination) {
            return ['status' => 'invalid_reference', 'bytes' => 0];
        }

        $disk = Storage::disk($diskName);
        $sourceFingerprint = $this->fingerprint($disk, $source);
        if ($sourceFingerprint === null) {
            return ['status' => 'missing_source', 'bytes' => 0];
        }
        if (($expectedSize !== null && $expectedSize !== $sourceFingerprint['size'])
            || ($this->validHash($expectedHash) && ! hash_equals(strtolower((string) $expectedHash), $sourceFingerprint['sha256']))) {
            return ['status' => 'source_mismatch', 'bytes' => $sourceFingerprint['size']];
        }

        $destinationFingerprint = $this->fingerprint($disk, $destination);
        $reuse = $destinationFingerprint !== null;
        if ($reuse && $destinationFingerprint !== $sourceFingerprint) {
            return ['status' => 'destination_collision', 'bytes' => $sourceFingerprint['size']];
        }
        if (! $apply) {
            return ['status' => $reuse ? 'planned_reuse' : 'planned', 'bytes' => $sourceFingerprint['size']];
        }

        if (! $reuse) {
            try {
                if (! $disk->copy($source, $destination)) {
                    return ['status' => 'copy_failed', 'bytes' => $sourceFingerprint['size']];
                }
            } catch (Throwable) {
                return ['status' => 'copy_failed', 'bytes' => $sourceFingerprint['size']];
            }

            $destinationFingerprint = $this->fingerprint($disk, $destination);
            if ($destinationFingerprint !== $sourceFingerprint) {
                try {
                    $disk->delete($destination);
                } catch (Throwable) {
                    // An unreferenced failed copy is safer than changing the reference.
                }

                return ['status' => 'copy_mismatch', 'bytes' => $sourceFingerprint['size']];
            }
        }

        try {
            $updated = DB::transaction(function () use (
                $artifact,
                $diskName,
                $table,
                $id,
                $column,
                $source,
                $destination,
                $sourceFingerprint,
            ): int {
                $updated = DB::table($table)
                    ->where('id', $id)
                    ->where($column, $source)
                    ->update([$column => $destination, 'updated_at' => now()]);
                if ($updated !== 1) {
                    return $updated;
                }

                DB::table('phr_blob_migrations')->updateOrInsert([
                    'reference_table' => $table,
                    'reference_id' => $id,
                    'reference_column' => $column,
                ], [
                    'patient_id' => (int) DB::table($table)->where('id', $id)->value('patient_id'),
                    'artifact_class' => $artifact,
                    'storage_disk' => $diskName,
                    'source_key' => $source,
                    'destination_key' => $destination,
                    'source_size_bytes' => $sourceFingerprint['size'],
                    'source_sha256' => $sourceFingerprint['sha256'],
                    'migrated_at' => now(),
                    'retain_until' => now()->addDays((int) config('phr.blob_migration_rollback_days', 30)),
                    'legacy_deleted_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $updated;
            });
        } catch (Throwable) {
            return ['status' => 'tracking_failed', 'bytes' => $sourceFingerprint['size']];
        }
        if ($updated !== 1) {
            return ['status' => 'stale_reference', 'bytes' => $sourceFingerprint['size']];
        }

        // Read through Laravel's configured disk after the reference changes. A
        // failure rolls the reference back with another compare-and-swap; legacy
        // bytes were never removed, so the application retains a readable source.
        if ($this->fingerprint($disk, $destination) !== $sourceFingerprint) {
            try {
                $rolledBack = $this->rollBackReference($table, $id, $column, $destination, $source);
            } catch (Throwable) {
                return ['status' => 'rollback_failed', 'bytes' => $sourceFingerprint['size']];
            }

            return [
                'status' => $rolledBack === 1 ? 'readback_failed' : 'rollback_failed',
                'bytes' => $sourceFingerprint['size'],
            ];
        }

        return ['status' => $reuse ? 'migrated_reuse' : 'migrated', 'bytes' => $sourceFingerprint['size']];
    }

    /** @return array{status: string, bytes: int} */
    private function verifyCanonicalReference(
        bool $apply,
        int $patientId,
        string $diskName,
        string $table,
        int $id,
        string $column,
        string $destination,
    ): array {
        $ledger = DB::table('phr_blob_migrations')
            ->where('patient_id', $patientId)
            ->where('reference_table', $table)
            ->where('reference_id', $id)
            ->where('reference_column', $column)
            ->where('destination_key', $destination)
            ->whereNull('legacy_deleted_at')
            ->first();
        if ($ledger === null) {
            return ['status' => 'already_canonical', 'bytes' => 0];
        }

        $expected = [
            'size' => (int) $ledger->source_size_bytes,
            'sha256' => (string) $ledger->source_sha256,
        ];
        $disk = Storage::disk($diskName);
        if ($this->fingerprint($disk, $destination) === $expected) {
            return ['status' => 'verified_canonical', 'bytes' => $expected['size']];
        }

        $source = (string) $ledger->source_key;
        if (! $this->validStoredKey($source)
            || ! $this->legacyPatientMatches($source, $patientId)
            || $this->fingerprint($disk, $source) !== $expected) {
            return ['status' => 'recovery_source_mismatch', 'bytes' => $expected['size']];
        }
        if (! $apply) {
            return ['status' => 'recovery_planned', 'bytes' => $expected['size']];
        }

        try {
            $rolledBack = $this->rollBackReference($table, $id, $column, $destination, $source);
        } catch (Throwable) {
            return ['status' => 'rollback_failed', 'bytes' => $expected['size']];
        }

        return [
            'status' => $rolledBack === 1 ? 'recovered_legacy' : 'stale_reference',
            'bytes' => $expected['size'],
        ];
    }

    private function rollBackReference(string $table, int $id, string $column, string $destination, string $source): int
    {
        return DB::transaction(function () use ($table, $id, $column, $destination, $source): int {
            $rolledBack = DB::table($table)
                ->where('id', $id)
                ->where($column, $destination)
                ->update([$column => $source, 'updated_at' => now()]);
            if ($rolledBack === 1) {
                DB::table('phr_blob_migrations')
                    ->where('reference_table', $table)
                    ->where('reference_id', $id)
                    ->where('reference_column', $column)
                    ->delete();
            }

            return $rolledBack;
        });
    }

    /** @return array{size: int, sha256: string}|null */
    private function fingerprint(Filesystem $disk, string $key): ?array
    {
        try {
            if (! $disk->exists($key)) {
                return null;
            }
            $stream = $disk->readStream($key);
            if (! is_resource($stream)) {
                return null;
            }

            try {
                $context = hash_init('sha256');
                $bytes = hash_update_stream($context, $stream);

                return ['size' => $bytes, 'sha256' => hash_final($context)];
            } finally {
                fclose($stream);
            }
        } catch (Throwable) {
            return null;
        }
    }

    private function selected(string $artifact, string $disk, ?string $diskFilter, ?string $artifactFilter): bool
    {
        return ($diskFilter === null || $diskFilter === $disk)
            && ($artifactFilter === null || $artifactFilter === $artifact);
    }

    private function stableUuid(string $table, int $id): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, $table.':'.$id)->toString();
    }

    private function isCanonical(string $key, int $patientId, string $area): bool
    {
        return str_starts_with($key, "patients/{$patientId}/{$area}/");
    }

    private function isCanonicalNamespace(string $key): bool
    {
        return str_starts_with($key, 'patients/');
    }

    private function legacyPatientMatches(string $key, int $patientId): bool
    {
        foreach ([
            '#^phr/documents/patients/(\d+)(?:/|$)#',
            '#^phr/exports/patients/(\d+)(?:/|$)#',
            '#^phr/dicom/patients/(\d+)(?:/|$)#',
            '#^derived/volume-cache/patients/(\d+)(?:/|$)#',
        ] as $pattern) {
            if (preg_match($pattern, $key, $matches) === 1) {
                return (int) $matches[1] === $patientId;
            }
        }

        return true;
    }

    private function validStoredKey(string $key): bool
    {
        if ($key === '' || str_starts_with($key, '/') || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            return false;
        }

        $segments = explode('/', str_replace('\\', '/', $key));

        return ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && ! in_array('..', $segments, true);
    }

    private function validHash(?string $hash): bool
    {
        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/i', $hash) === 1;
    }

    private function scopePatient(\Illuminate\Database\Query\Builder $query, ?int $patientId): void
    {
        if ($patientId !== null) {
            $query->where('patient_id', $patientId);
        }
    }
}
