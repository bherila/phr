<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrDicomFile;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

final class PhrNativeArchiveBuilder
{
    public function __construct(
        private readonly PhrNativeSnapshotService $snapshotService,
        private readonly PhrNativeIdentityRepository $identityRepository,
        private readonly PhrNativeRecordCodec $codec,
    ) {}

    public function build(PhrPatient $patient): NativeBackupBuildResult
    {
        $patientId = (int) $patient->id;
        $rows = $this->snapshotService->rows($patientId);
        $identities = $this->identities($patientId, $rows);
        $maxBytes = (int) config('phr.native_backup_max_uncompressed_bytes', 20 * 1024 * 1024 * 1024);
        $artifacts = $this->artifacts($patientId, $rows, $identities, $maxBytes);
        $targetPath = tempnam(sys_get_temp_dir(), 'phr-native-');
        if ($targetPath === false) {
            throw new NativeBackupException('temporary_storage_failed');
        }

        $output = fopen($targetPath, 'w+b');
        if ($output === false) {
            @unlink($targetPath);
            throw new NativeBackupException('temporary_storage_failed');
        }

        $counts = [];
        $tableManifest = [];
        $uncompressedBytes = 0;

        try {
            // R6: ZipStream writes sequentially to a seekable private temp file, uses
            // Zip64 for >4 GiB archives / >65k entries, and zero headers so each input
            // stream is read once. STORE avoids wasting CPU recompressing DICOM.
            $zip = new ZipStream(
                outputStream: $output,
                defaultCompressionMethod: CompressionMethod::STORE,
                enableZip64: true,
                defaultEnableZeroHeader: true,
                sendHttpHeaders: false,
            );

            foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
                $recordPath = 'records/'.$table.'.ndjson';
                $recordStream = tmpfile();
                if ($recordStream === false) {
                    throw new NativeBackupException('temporary_storage_failed');
                }

                try {
                    foreach ($rows[$table] as $row) {
                        $record = $this->encodeRecord($patientId, $table, $definition, $row, $identities);
                        $line = PhrNativeRecordCodec::canonicalJson($record)."\n";
                        if (fwrite($recordStream, $line) === false) {
                            throw new NativeBackupException('temporary_storage_failed');
                        }
                    }

                    $recordSize = ftell($recordStream);
                    if (! is_int($recordSize)) {
                        throw new NativeBackupException('temporary_storage_failed');
                    }
                    $uncompressedBytes = $this->checkedTotal($uncompressedBytes, $recordSize, $maxBytes);
                    rewind($recordStream);
                    $hashContext = hash_init('sha256');
                    hash_update_stream($hashContext, $recordStream);
                    $recordHash = hash_final($hashContext);
                    rewind($recordStream);

                    $zip->addFileFromStream(
                        fileName: $recordPath,
                        stream: $recordStream,
                        compressionMethod: CompressionMethod::DEFLATE,
                    );
                    $count = $rows[$table]->count();
                    $counts[$table] = $count;
                    $tableManifest[$table] = [
                        'path' => $recordPath,
                        'count' => $count,
                        'sha256' => $recordHash,
                    ];
                } finally {
                    fclose($recordStream);
                }
            }

            foreach ($artifacts as $artifact) {
                $uncompressedBytes = $this->checkedTotal($uncompressedBytes, $artifact['size'], $maxBytes);
                $stream = Storage::disk($artifact['disk'])->readStream($artifact['source_key']);
                if (! is_resource($stream)) {
                    throw new NativeBackupException('artifact_unreadable');
                }

                try {
                    $zip->addFileFromStream(
                        fileName: $artifact['path'],
                        stream: $stream,
                        compressionMethod: CompressionMethod::STORE,
                        exactSize: $artifact['size'],
                    );
                } finally {
                    fclose($stream);
                }
            }

            $manifest = $this->manifest($patientId, $identities['phr_patients'][$patientId], $tableManifest, $artifacts, $maxBytes, $uncompressedBytes);
            $manifestJson = PhrNativeRecordCodec::canonicalJson($manifest)."\n";
            $this->checkedTotal($uncompressedBytes, strlen($manifestJson), $maxBytes);
            $zip->addFile('manifest.json', $manifestJson, compressionMethod: CompressionMethod::DEFLATE);
            $zip->finish();
        } catch (NativeBackupException $exception) {
            fclose($output);
            @unlink($targetPath);
            throw $exception;
        } catch (\Throwable) {
            fclose($output);
            @unlink($targetPath);
            throw new NativeBackupException('archive_write_failed');
        }

        fclose($output);
        $fileSize = filesize($targetPath);
        $sha256 = hash_file('sha256', $targetPath);
        if ($fileSize === false || $sha256 === false) {
            @unlink($targetPath);
            throw new NativeBackupException('archive_verification_failed');
        }

        return new NativeBackupBuildResult($targetPath, $fileSize, $sha256, $manifest, $counts);
    }

    /**
     * @param  array<string, Collection<int, \stdClass>>  $rows
     * @return array<string, array<int, string>>
     */
    private function identities(int $patientId, array $rows): array
    {
        $identities = [];
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            foreach ($rows[$table] as $row) {
                $recordId = (int) $row->id;
                $identities[$table][$recordId] = $this->identityRepository->forRecord($patientId, $table, $recordId);

                foreach ($definition['relationships'] ?? [] as $column => $relationship) {
                    $relatedId = $row->{$column};
                    if ($relatedId !== null && $relationship['kind'] === 'actor') {
                        $actorId = (int) $relatedId;
                        $identities['users'][$actorId] = $this->identityRepository->forRecord($patientId, 'users', $actorId);
                    }
                }
            }
        }

        return $identities;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<int, string>>  $identities
     * @return array{nativeId: string, contentHash: string, attributes: array<string, mixed>, relationships: array<string, mixed>}
     */
    private function encodeRecord(int $patientId, string $table, array $definition, \stdClass $row, array $identities): array
    {
        $recordId = (int) $row->id;
        $relationshipDefinitions = $definition['relationships'] ?? [];
        $excludedColumns = array_keys($definition['excluded_columns'] ?? []);
        $attributes = [];

        foreach ((array) $row as $column => $value) {
            if ($column === 'id' || $column === $definition['patient_column'] || isset($relationshipDefinitions[$column]) || in_array($column, $excludedColumns, true)) {
                continue;
            }
            $attributes[$column] = $this->codec->encodeValue($table, $column, $value);
        }

        $relationships = [];
        if (! ($definition['root'] ?? false)) {
            $relationships[$definition['patient_column']] = [
                'kind' => 'record',
                'table' => 'phr_patients',
                'nativeId' => $identities['phr_patients'][$patientId],
            ];
        }

        foreach ($relationshipDefinitions as $column => $relationship) {
            $relatedId = $row->{$column};
            if ($relatedId === null) {
                $relationships[$column] = null;

                continue;
            }

            $target = $relationship['target'];
            $nativeId = $identities[$target][(int) $relatedId] ?? null;
            if ($nativeId === null && ! $relationship['nullable']) {
                throw new NativeBackupException('relationship_missing');
            }

            $relationships[$column] = $nativeId === null ? null : [
                'kind' => $relationship['kind'],
                'table' => $target,
                'nativeId' => $nativeId,
            ];
        }

        return $this->codec->record($identities[$table][$recordId], $attributes, $relationships);
    }

    /**
     * @param  array<string, Collection<int, \stdClass>>  $rows
     * @param  array<string, array<int, string>>  $identities
     * @return list<array{kind: string, recordNativeId: string, path: string, size: int, sha256: string, disk: string, source_key: string}>
     */
    private function artifacts(int $patientId, array $rows, array $identities, int $maxBytes): array
    {
        $candidates = [];
        foreach ($rows['phr_documents'] as $row) {
            if ($row->storage_path === null || $row->storage_path === '') {
                continue;
            }
            $candidates[] = [
                'kind' => 'document',
                'recordNativeId' => $identities['phr_documents'][(int) $row->id],
                'path' => 'artifacts/documents/'.$identities['phr_documents'][(int) $row->id].'.blob',
                'disk' => PhrDocument::STORAGE_DISK,
                'source_key' => (string) $row->storage_path,
                'expected_size' => (int) $row->byte_size,
                'expected_hash' => $row->file_hash === null ? null : strtolower((string) $row->file_hash),
            ];
        }
        foreach ($rows['phr_dicom_files'] as $row) {
            $candidates[] = [
                'kind' => $row->file_kind === PhrDicomFile::KIND_DICOMDIR ? 'dicomdir' : 'dicom',
                'recordNativeId' => $identities['phr_dicom_files'][(int) $row->id],
                'path' => 'artifacts/dicom/'.$identities['phr_dicom_files'][(int) $row->id].'.blob',
                'disk' => 'phr_dicom',
                'source_key' => (string) $row->r2_key,
                'expected_size' => (int) $row->file_size_bytes,
                'expected_hash' => strtolower((string) $row->sha256),
            ];
        }

        $expectedTotal = array_sum(array_column($candidates, 'expected_size'));
        if ($expectedTotal > $maxBytes) {
            throw new NativeBackupException('size_limit');
        }

        $artifacts = [];
        $actualTotal = 0;
        foreach ($candidates as $candidate) {
            $stream = Storage::disk($candidate['disk'])->readStream($candidate['source_key']);
            if (! is_resource($stream)) {
                throw new NativeBackupException('artifact_unreadable');
            }

            try {
                $context = hash_init('sha256');
                $size = hash_update_stream($context, $stream);
                $sha256 = hash_final($context);
            } finally {
                fclose($stream);
            }

            if ($size !== $candidate['expected_size']) {
                throw new NativeBackupException('artifact_size_mismatch');
            }
            if ($candidate['expected_hash'] !== null && ! hash_equals($candidate['expected_hash'], $sha256)) {
                throw new NativeBackupException('artifact_hash_mismatch');
            }

            $actualTotal = $this->checkedTotal($actualTotal, $size, $maxBytes);
            $artifacts[] = [
                'kind' => $candidate['kind'],
                'recordNativeId' => $candidate['recordNativeId'],
                'path' => $candidate['path'],
                'size' => $size,
                'sha256' => $sha256,
                'disk' => $candidate['disk'],
                'source_key' => $candidate['source_key'],
            ];
        }

        return $artifacts;
    }

    /**
     * @param  array<string, array{path: string, count: int, sha256: string}>  $tables
     * @param  list<array{kind: string, recordNativeId: string, path: string, size: int, sha256: string, disk: string, source_key: string}>  $artifacts
     * @return array<string, mixed>
     */
    private function manifest(int $patientId, string $patientNativeId, array $tables, array $artifacts, int $maxBytes, int $uncompressedBytes): array
    {
        $artifactManifest = array_map(
            static fn (array $artifact): array => [
                'kind' => $artifact['kind'],
                'recordNativeId' => $artifact['recordNativeId'],
                'path' => $artifact['path'],
                'size' => $artifact['size'],
                'sha256' => $artifact['sha256'],
            ],
            $artifacts,
        );

        $columnExclusions = [];
        $rowPolicies = [];
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            if (($definition['excluded_columns'] ?? []) !== []) {
                $columnExclusions[$table] = $definition['excluded_columns'];
            }
            if (isset($definition['row_policy'])) {
                $rowPolicies[$table] = $definition['row_policy'];
            }
        }

        return [
            'format' => PhrNativeBackupCatalog::FORMAT,
            'schemaVersion' => PhrNativeBackupCatalog::SCHEMA_VERSION,
            'createdAt' => now()->toIso8601String(),
            'patientNativeId' => $patientNativeId,
            'container' => [
                'mediaType' => 'application/zip',
                'zip64' => true,
                'compression' => 'stored-artifacts',
                'maxUncompressedBytes' => $maxBytes,
                'uncompressedBytesBeforeManifest' => $uncompressedBytes,
                'oversizePolicy' => 'fail_closed',
            ],
            'identity' => [
                'scheme' => 'durable-opaque-uuid',
                'reason' => 'Stable across edits, duplicate records, re-backups, and restored database ids.',
            ],
            'tables' => $tables,
            'artifacts' => $artifactManifest,
            'exclusions' => [
                'tables' => PhrNativeBackupCatalog::excluded(),
                'columns' => $columnExclusions,
                'rowPolicies' => $rowPolicies,
            ],
            'decisions' => [
                'softDeletedDocuments' => 'included_with_deleted_at_and_source_bytes',
                'accessGrants' => 'review_only_never_auto_restore',
                'actorReferences' => 'opaque_native_ids_without_user_identifiers',
            ],
        ];
    }

    private function checkedTotal(int $current, int $addition, int $max): int
    {
        if ($addition < 0 || $current > $max - $addition) {
            throw new NativeBackupException('size_limit');
        }

        return $current + $addition;
    }
}
