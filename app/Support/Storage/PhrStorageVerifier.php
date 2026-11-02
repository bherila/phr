<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Compares the objects on PHR's disks with what the database says they should be: that
 * each exists, its size, and its hash where a row records one. Only ever reads, so it can
 * run against either side of a move, and comparing the two reports shows the copy is whole.
 *
 * It reports table and row id, never a key: a key can hold an original filename.
 */
final class PhrStorageVerifier
{
    public const array CHECKS = ['missing', 'wrong_size', 'wrong_hash', 'unreadable'];

    private const int MAX_PROBLEMS = 100;

    /**
     * Every column that names a stored object, and what can be checked about it. Rows
     * matched by `in_flight` are mid-upload or awaiting deletion, so their objects may
     * rightly be absent: they are counted, not checked. A move should wait for them.
     * PhrStorageVerifierTest fails if PhrStorageMap references a column missing here.
     *
     * @return list<array{table: string, disk: string|null, fixed_disk?: string, key: string, size: string|null, hash: string|null, where?: array<string, null>, in_flight?: list<string>|true, status?: string, not_an_object?: true}>
     */
    public static function sources(): array
    {
        return [
            ['table' => 'phr_documents', 'disk' => 'storage_disk', 'key' => 'storage_path', 'size' => 'byte_size', 'hash' => 'file_hash'],
            ['table' => 'phr_dicom_files', 'disk' => null, 'fixed_disk' => 'phr_dicom', 'key' => 'r2_key', 'size' => 'file_size_bytes', 'hash' => 'sha256'],
            ['table' => 'phr_ehi_blobs', 'disk' => 'storage_disk', 'key' => 'storage_path', 'size' => 'byte_size', 'hash' => 'sha256'],
            ['table' => 'phr_exports', 'disk' => 'storage_disk', 'key' => 'storage_path', 'size' => 'file_size_bytes', 'hash' => null],
            ['table' => 'phr_native_backups', 'disk' => 'storage_disk', 'key' => 'storage_path', 'size' => 'file_size_bytes', 'hash' => 'archive_sha256'],
            ['table' => 'phr_blob_migrations', 'disk' => 'storage_disk', 'key' => 'source_key', 'size' => 'source_size_bytes', 'hash' => 'source_sha256', 'where' => ['legacy_deleted_at' => null]],
            ['table' => 'phr_blob_migrations', 'disk' => 'storage_disk', 'key' => 'destination_key', 'size' => 'source_size_bytes', 'hash' => 'source_sha256', 'where' => ['legacy_deleted_at' => null]],
            // Not its file_hash: a job for a document with no hash records a hash of the key.
            ['table' => 'genai_import_jobs', 'disk' => null, 'fixed_disk' => 's3', 'key' => 's3_path', 'size' => 'file_size_bytes', 'hash' => null],
            ['table' => 'phr_native_restore_attempts', 'disk' => 'source_storage_disk', 'key' => 'source_storage_path', 'size' => 'source_file_size_bytes', 'hash' => 'archive_sha256', 'status' => 'status', 'in_flight' => ['uploading', 'preview_pending']],
            ['table' => 'phr_ehi_uploads', 'disk' => 'storage_disk', 'key' => 'storage_path', 'size' => 'file_size_bytes', 'hash' => null, 'status' => 'status', 'in_flight' => ['uploading', 'queued', 'processing']],
            ['table' => 'phr_document_uploads', 'disk' => 'storage_disk', 'key' => 'staging_path', 'size' => null, 'hash' => null, 'in_flight' => true],
            // A prefix, not an object: only a pending upload means anything here.
            ['table' => 'phr_dicom_uploads', 'disk' => null, 'fixed_disk' => 'phr_dicom', 'key' => 'r2_prefix', 'size' => null, 'hash' => null, 'status' => 'status', 'in_flight' => ['pending'], 'not_an_object' => true],
            ['table' => 'phr_patient_deletion_artifacts', 'disk' => 'storage_disk', 'key' => 'storage_key', 'size' => null, 'hash' => null, 'in_flight' => true],
        ];
    }

    /**
     * @param  list<string>  $onlyDisks  empty for every disk
     * @return array{disks: array<string, array<string, int>>, problems: list<array{table: string, id: int|null, problem: string}>, problem_count: int}
     */
    public function verify(array $onlyDisks = [], bool $hashes = true): array
    {
        $disks = [];
        $problems = [];
        $problemCount = 0;
        $tally = static function (array &$disks, string $disk, string $what, int $by = 1): void {
            $disks[$disk] ??= self::emptyTally();
            $disks[$disk][$what] += $by;
        };
        foreach (array_keys(PhrStorageMap::disks()) as $disk) {
            if ($onlyDisks === [] || in_array($disk, $onlyDisks, true)) {
                $disks[$disk] = self::emptyTally();
            }
        }

        foreach (self::sources() as $source) {
            if (! Schema::hasTable($source['table'])) {
                continue;
            }
            $query = DB::table($source['table'])->whereNotNull($source['key'])->where($source['key'], '!=', '');
            foreach ($source['where'] ?? [] as $column => $value) {
                $query->where($column, $value);
            }
            foreach ($query->lazyById(500, 'id') as $row) {
                $key = (string) $row->{$source['key']};
                $disk = $source['disk'] !== null ? (string) $row->{$source['disk']} : (string) $source['fixed_disk'];
                // A pasted GenAI import holds a sentinel like inline://paste/<uuid>, not a key.
                if (($onlyDisks !== [] && ! in_array($disk, $onlyDisks, true)) || str_contains($key, '://')) {
                    continue;
                }
                $inFlight = $source['in_flight'] ?? [];
                if ($inFlight === true || (isset($source['status']) && in_array($row->{$source['status']}, $inFlight, true))) {
                    $tally($disks, $disk, 'in_flight');

                    continue;
                }
                if (isset($source['not_an_object'])) {
                    continue;
                }

                $tally($disks, $disk, 'rows');
                [$problem, $bytes, $hashChecked] = $this->check(
                    $disk,
                    $key,
                    $source['size'] !== null && is_numeric($row->{$source['size']}) ? (int) $row->{$source['size']} : null,
                    $hashes && $source['hash'] !== null ? (string) $row->{$source['hash']} : '',
                );
                if ($problem !== null) {
                    $tally($disks, $disk, $problem);
                    $problemCount++;
                    if (count($problems) < self::MAX_PROBLEMS) {
                        $problems[] = ['table' => $source['table'], 'id' => (int) $row->id, 'problem' => $problem];
                    }

                    continue;
                }
                $tally($disks, $disk, 'ok');
                $tally($disks, $disk, 'bytes', $bytes);
                $tally($disks, $disk, $hashChecked ? 'hashed' : 'size_only');
            }
        }

        $references = PhrStorageMap::references();
        $keys = $references->referencedKeys();
        $prefixes = $references->referencedPrefixes();
        foreach (PhrStorageMap::disks() as $disk => $roots) {
            if ($onlyDisks !== [] && ! in_array($disk, $onlyDisks, true)) {
                continue;
            }
            try {
                $filesystem = Storage::disk($disk);
                foreach ($roots as $root) {
                    foreach ($filesystem->allFiles($root) as $listed) {
                        $tally($disks, $disk, 'objects');
                        if (! BlobReferences::covers($listed, $keys, $prefixes)) {
                            $tally($disks, $disk, 'unreferenced');
                        }
                    }
                }
                $tally($disks, $disk, 'quarantined', count($filesystem->allFiles(StoragePruner::QUARANTINE_ROOT)));
            } catch (Throwable) {
                $tally($disks, $disk, 'unreadable');
                $problemCount++;
                $problems[] = ['table' => $disk, 'id' => null, 'problem' => 'could not be listed'];
            }
        }

        return ['disks' => $disks, 'problems' => $problems, 'problem_count' => $problemCount];
    }

    /** @return array{0: string|null, 1: int, 2: bool} the problem if any, the object's size, and whether its hash was compared */
    private function check(string $disk, string $key, ?int $expectedBytes, string $expectedHash): array
    {
        try {
            $filesystem = Storage::disk($disk);
            if (! $filesystem->exists($key)) {
                return ['missing', 0, false];
            }
            $bytes = $filesystem->size($key);
            if ($expectedBytes !== null && $bytes !== $expectedBytes) {
                return ['wrong_size', 0, false];
            }
            if (preg_match('/\A[0-9a-f]{64}\z/i', $expectedHash) !== 1) {
                return [null, $bytes, false];
            }
            $stream = $filesystem->readStream($key);
            if (! is_resource($stream)) {
                return ['unreadable', 0, false];
            }
            try {
                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
            } finally {
                fclose($stream);
            }

            return hash_equals(strtolower($expectedHash), hash_final($context)) ? [null, $bytes, true] : ['wrong_hash', 0, false];
        } catch (Throwable) {
            return ['unreadable', 0, false];
        }
    }

    /** @return array<string, int> */
    private static function emptyTally(): array
    {
        return ['rows' => 0, 'ok' => 0, 'missing' => 0, 'wrong_size' => 0, 'wrong_hash' => 0, 'unreadable' => 0, 'hashed' => 0, 'size_only' => 0, 'bytes' => 0, 'in_flight' => 0, 'objects' => 0, 'unreferenced' => 0, 'quarantined' => 0];
    }
}
