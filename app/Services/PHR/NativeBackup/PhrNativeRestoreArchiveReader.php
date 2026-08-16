<?php

namespace App\Services\PHR\NativeBackup;

use App\Support\Storage\PhrStorageKey;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

final class PhrNativeRestoreArchiveReader
{
    public function openFromStorage(string $disk, string $path): PhrNativeRestoreArchive
    {
        $localPath = tempnam(sys_get_temp_dir(), 'phr-restore-');
        if ($localPath === false) {
            throw new NativeRestoreException('temporary_storage_failed');
        }

        $source = Storage::disk($disk)->readStream($path);
        $target = fopen($localPath, 'w+b');
        if (! is_resource($source) || ! is_resource($target)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($localPath);
            throw new NativeRestoreException('source_unreadable');
        }

        try {
            $bytes = stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
        if (! is_int($bytes)) {
            @unlink($localPath);
            throw new NativeRestoreException('source_unreadable');
        }

        return $this->openLocal($localPath);
    }

    public function openLocal(string $localPath): PhrNativeRestoreArchive
    {
        $fileSize = filesize($localPath);
        $sha256 = hash_file('sha256', $localPath);
        $maxBytes = (int) config('phr.native_backup_max_uncompressed_bytes');
        if (! is_int($fileSize) || ! is_string($sha256) || $fileSize < 1 || $fileSize > $maxBytes) {
            @unlink($localPath);
            throw new NativeRestoreException('size_limit');
        }

        $zip = new ZipArchive;
        if ($zip->open($localPath, ZipArchive::RDONLY) !== true) {
            @unlink($localPath);
            throw new NativeRestoreException('invalid_archive');
        }

        try {
            $manifestBytes = $zip->getFromName('manifest.json', 1024 * 1024);
            if (! is_string($manifestBytes) || strlen($manifestBytes) >= 1024 * 1024) {
                throw new NativeRestoreException('invalid_archive');
            }
            $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new NativeRestoreException('invalid_archive');
            }
            $this->validateManifest($manifest);
            $this->validateEntries($zip, $manifest, $maxBytes);
            $maxRecordBytes = (int) config('phr.native_restore_max_record_bytes');
            $artifactRecords = $this->validateRecords($zip, $manifest, $maxRecordBytes);
            $this->validateArtifacts($zip, $manifest, $artifactRecords);
        } catch (NativeRestoreException $exception) {
            $zip->close();
            @unlink($localPath);
            throw $exception;
        } catch (\Throwable) {
            $zip->close();
            @unlink($localPath);
            throw new NativeRestoreException('invalid_archive');
        }
        $zip->close();

        return new PhrNativeRestoreArchive($localPath, $manifest, $fileSize, $sha256, $maxRecordBytes);
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== PhrNativeBackupCatalog::FORMAT
            || ($manifest['schemaVersion'] ?? null) !== PhrNativeBackupCatalog::SCHEMA_VERSION) {
            throw new NativeRestoreException('unsupported_schema');
        }
        if (! Str::isUuid($manifest['patientNativeId'] ?? null)) {
            throw new NativeRestoreException('invalid_archive');
        }
        $tables = $manifest['tables'] ?? null;
        $actualTables = is_array($tables) ? array_keys($tables) : [];
        $expectedTables = array_keys(PhrNativeBackupCatalog::included());
        sort($actualTables);
        sort($expectedTables);
        if (! is_array($tables) || $actualTables !== $expectedTables) {
            throw new NativeRestoreException('invalid_archive');
        }
        if (! is_array($manifest['artifacts'] ?? null)) {
            throw new NativeRestoreException('invalid_archive');
        }

        foreach ($tables as $table => $entry) {
            if (! is_array($entry)
                || ($entry['path'] ?? null) !== 'records/'.$table.'.ndjson'
                || ! is_int($entry['count'] ?? null) || $entry['count'] < 0
                || ! $this->isHash($entry['sha256'] ?? null)) {
                throw new NativeRestoreException('invalid_archive');
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateEntries(ZipArchive $zip, array $manifest, int $maxBytes): void
    {
        $allowed = ['manifest.json' => true];
        foreach ($manifest['tables'] as $entry) {
            $allowed[$entry['path']] = true;
        }
        foreach ($manifest['artifacts'] as $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['path'] ?? null)) {
                throw new NativeRestoreException('invalid_archive');
            }
            $allowed[$artifact['path']] = true;
        }

        $seen = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                throw new NativeRestoreException('invalid_archive');
            }
            $name = $stat['name'];
            if (isset($seen[$name]) || ! isset($allowed[$name]) || $name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', explode('/', $name), true)) {
                throw new NativeRestoreException('invalid_archive');
            }
            $seen[$name] = true;
            if ($stat['size'] < 0 || $total > $maxBytes - $stat['size']) {
                throw new NativeRestoreException('size_limit');
            }
            $total += $stat['size'];
            if ($stat['encryption_method'] !== 0) {
                throw new NativeRestoreException('invalid_archive');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new NativeRestoreException('invalid_archive');
                }
            }
        }
        if (array_diff_key($allowed, $seen) !== []) {
            throw new NativeRestoreException('invalid_archive');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function validateRecords(ZipArchive $zip, array $manifest, int $maxRecordBytes): array
    {
        $allIds = [];
        $actorIds = [];
        $artifactRecords = ['phr_documents' => [], 'phr_dicom_files' => []];
        foreach (PhrNativeBackupCatalog::included() as $table => $definition) {
            $entry = $manifest['tables'][$table];
            $stream = $zip->getStream($entry['path']);
            if (! is_resource($stream)) {
                throw new NativeRestoreException('invalid_archive');
            }
            $hash = hash_init('sha256');
            $count = 0;
            $ids = [];
            try {
                while (($line = fgets($stream, $maxRecordBytes + 2)) !== false) {
                    if (strlen($line) > $maxRecordBytes || (! str_ends_with($line, "\n") && ! feof($stream))) {
                        throw new NativeRestoreException('record_size_limit');
                    }
                    hash_update($hash, $line);
                    $trimmed = rtrim($line, "\r\n");
                    if ($trimmed === '') {
                        continue;
                    }
                    $record = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($record)) {
                        throw new NativeRestoreException('invalid_archive');
                    }
                    $this->validateRecord($table, $definition, $record);
                    if (isset($ids[$record['nativeId']])) {
                        throw new NativeRestoreException('invalid_archive');
                    }
                    if (isset($allIds[$record['nativeId']])) {
                        throw new NativeRestoreException('invalid_archive');
                    }
                    if (isset($actorIds[$record['nativeId']])
                        || (($definition['root'] ?? false) && $record['nativeId'] !== $manifest['patientNativeId'])) {
                        throw new NativeRestoreException('invalid_archive');
                    }
                    $ids[$record['nativeId']] = true;
                    $allIds[$record['nativeId']] = true;
                    foreach ($record['relationships'] as $relationship) {
                        if (($relationship['kind'] ?? null) !== 'actor') {
                            continue;
                        }
                        $actorNativeId = $relationship['nativeId'];
                        if (isset($allIds[$actorNativeId])) {
                            throw new NativeRestoreException('invalid_archive');
                        }
                        $actorIds[$actorNativeId] = true;
                    }
                    if (isset($artifactRecords[$table])) {
                        $artifactRecords[$table][$record['nativeId']] = $record['attributes'];
                    }
                    $count++;
                }
            } finally {
                fclose($stream);
            }
            if ($count !== $entry['count'] || ! hash_equals($entry['sha256'], hash_final($hash))) {
                throw new NativeRestoreException('archive_hash_mismatch');
            }
            if (($definition['root'] ?? false) && $count !== 1) {
                throw new NativeRestoreException('invalid_archive');
            }
        }

        return $artifactRecords;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $record
     */
    private function validateRecord(string $table, array $definition, array $record): void
    {
        if (array_keys($record) !== ['attributes', 'contentHash', 'nativeId', 'relationships']
            || ! Str::isUuid($record['nativeId'] ?? null)
            || ! $this->isHash($record['contentHash'] ?? null)
            || ! is_array($record['attributes'] ?? null)
            || ! is_array($record['relationships'] ?? null)) {
            throw new NativeRestoreException('invalid_archive');
        }
        $expectedAttributes = [];
        $relationships = $definition['relationships'] ?? [];
        $excluded = $definition['excluded_columns'] ?? [];
        foreach (Schema::getColumnListing($table) as $column) {
            if ($column !== 'id' && $column !== $definition['patient_column'] && ! isset($relationships[$column]) && ! isset($excluded[$column])) {
                $expectedAttributes[] = $column;
            }
        }
        sort($expectedAttributes);
        $actualAttributes = array_keys($record['attributes']);
        sort($actualAttributes);
        if ($expectedAttributes !== $actualAttributes) {
            throw new NativeRestoreException('invalid_archive');
        }

        $expectedRelationships = array_keys($relationships);
        if (! ($definition['root'] ?? false)) {
            $expectedRelationships[] = $definition['patient_column'];
        }
        sort($expectedRelationships);
        $actualRelationships = array_keys($record['relationships']);
        sort($actualRelationships);
        if ($expectedRelationships !== $actualRelationships) {
            throw new NativeRestoreException('invalid_archive');
        }
        foreach ($record['relationships'] as $column => $relationship) {
            if ($relationship === null) {
                $nullable = ($relationships[$column]['nullable'] ?? false);
                if ($column === $definition['patient_column'] || ! $nullable) {
                    throw new NativeRestoreException('invalid_archive');
                }

                continue;
            }
            $expected = $column === $definition['patient_column']
                ? ['kind' => 'record', 'target' => 'phr_patients']
                : ['kind' => $relationships[$column]['kind'], 'target' => $relationships[$column]['target']];
            if (! is_array($relationship)
                || ($relationship['kind'] ?? null) !== $expected['kind']
                || ($relationship['table'] ?? null) !== $expected['target']
                || ! Str::isUuid($relationship['nativeId'] ?? null)) {
                throw new NativeRestoreException('invalid_archive');
            }
        }
        $content = ['attributes' => $record['attributes'], 'relationships' => $record['relationships']];
        if (! hash_equals($record['contentHash'], hash('sha256', PhrNativeRecordCodec::canonicalJson($content)))) {
            throw new NativeRestoreException('archive_hash_mismatch');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array<string, array<string, mixed>>>  $artifactRecords
     */
    private function validateArtifacts(ZipArchive $zip, array $manifest, array $artifactRecords): void
    {
        $seenPaths = [];
        $seenRecords = [];
        foreach ($manifest['artifacts'] as $artifact) {
            if (! is_array($artifact)
                || ! in_array($artifact['kind'] ?? null, ['document', 'dicom', 'dicomdir'], true)
                || ! Str::isUuid($artifact['recordNativeId'] ?? null)
                || ! is_string($artifact['path'] ?? null)
                || ! is_int($artifact['size'] ?? null) || $artifact['size'] < 0
                || ! $this->isHash($artifact['sha256'] ?? null)
                || isset($seenPaths[$artifact['path']]) || isset($seenRecords[$artifact['recordNativeId']])) {
                throw new NativeRestoreException('invalid_archive');
            }
            $seenPaths[$artifact['path']] = true;
            $seenRecords[$artifact['recordNativeId']] = true;
            $table = $artifact['kind'] === 'document' ? 'phr_documents' : 'phr_dicom_files';
            $attributes = $artifactRecords[$table][$artifact['recordNativeId']] ?? null;
            $expectedPath = $artifact['kind'] === 'document'
                ? 'artifacts/documents/'.$artifact['recordNativeId'].'.blob'
                : 'artifacts/dicom/'.$artifact['recordNativeId'].'.blob';
            if (! is_array($attributes) || $artifact['path'] !== $expectedPath) {
                throw new NativeRestoreException('invalid_archive');
            }
            if ($table === 'phr_documents') {
                if ((int) ($attributes['byte_size'] ?? -1) !== $artifact['size']
                    || (($attributes['file_hash'] ?? null) !== null && ! hash_equals(strtolower((string) $attributes['file_hash']), (string) $artifact['sha256']))) {
                    throw new NativeRestoreException('archive_hash_mismatch');
                }
            } else {
                $expectedKind = ($attributes['file_kind'] ?? null) === 'dicomdir' ? 'dicomdir' : 'dicom';
                if ($artifact['kind'] !== $expectedKind
                    || (int) ($attributes['file_size_bytes'] ?? -1) !== $artifact['size']
                    || ! hash_equals(strtolower((string) ($attributes['sha256'] ?? '')), (string) $artifact['sha256'])) {
                    throw new NativeRestoreException('archive_hash_mismatch');
                }
                try {
                    PhrStorageKey::dicomObject('validated-upload', (string) ($attributes['original_relative_path'] ?? ''));
                } catch (\InvalidArgumentException) {
                    throw new NativeRestoreException('invalid_archive');
                }
            }
            $stream = $zip->getStream($artifact['path']);
            if (! is_resource($stream)) {
                throw new NativeRestoreException('invalid_archive');
            }
            try {
                $context = hash_init('sha256');
                $size = hash_update_stream($context, $stream);
            } finally {
                fclose($stream);
            }
            if ($size !== $artifact['size'] || ! hash_equals($artifact['sha256'], hash_final($context))) {
                throw new NativeRestoreException('archive_hash_mismatch');
            }
        }
        if (array_diff_key($artifactRecords['phr_dicom_files'], $seenRecords) !== []) {
            throw new NativeRestoreException('invalid_archive');
        }
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
