<?php

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Retires legacy migration copies only after their rollback window expires.
 *
 * Cleanup locks the referenced row before its ledger row, matching recovery's
 * reference-then-ledger order. The lock remains held while both objects are
 * reverified and the legacy object is deleted, so a concurrent recovery cannot
 * repoint the application to bytes cleanup is about to remove.
 */
final class PhrBlobCleanupService
{
    /** @var array<string, array{disk: string, table: string, column: string}> */
    private const array ARTIFACTS = [
        'documents' => ['disk' => 'phr_documents', 'table' => 'phr_documents', 'column' => 'storage_path'],
        'dicom-originals' => ['disk' => 'phr_dicom', 'table' => 'phr_dicom_files', 'column' => 'r2_key'],
        'dicom-derived' => ['disk' => 'phr_dicom', 'table' => 'phr_dicom_files', 'column' => 'r2_key'],
        'exports' => ['disk' => 'phr_exports', 'table' => 'phr_exports', 'column' => 'storage_path'],
        'native-backups' => ['disk' => 'phr_exports', 'table' => 'phr_native_backups', 'column' => 'storage_path'],
    ];

    public const array ARTIFACT_NAMES = [
        'documents',
        'dicom-originals',
        'dicom-derived',
        'exports',
        'native-backups',
    ];

    public const array DISKS = ['phr_documents', 'phr_dicom', 'phr_exports'];

    /**
     * @param  callable(array{artifact: string, table: string, id: int, status: string}): void|null  $reporter
     */
    public function run(
        bool $apply,
        ?string $disk = null,
        ?string $artifact = null,
        ?int $patientId = null,
        ?callable $reporter = null,
    ): PhrBlobCleanupSummary {
        $summary = new PhrBlobCleanupSummary;
        $query = DB::table('phr_blob_migrations')
            ->whereNull('legacy_deleted_at')
            ->orderBy('id');
        if ($disk !== null) {
            $query->where('storage_disk', $disk);
        }
        if ($artifact !== null) {
            $query->where('artifact_class', $artifact);
        }
        if ($patientId !== null) {
            $query->where('patient_id', $patientId);
        }

        $query->chunkById(100, function ($rows) use ($apply, $reporter, $summary): void {
            foreach ($rows as $row) {
                $outcome = now()->lt($row->retain_until)
                    ? ['status' => 'retained', 'bytes' => (int) $row->source_size_bytes]
                    : $this->cleanupOne((int) $row->id, $apply);
                $summary->record($outcome['status'], $outcome['bytes']);
                if ($reporter !== null) {
                    $reporter([
                        'artifact' => (string) $row->artifact_class,
                        'table' => (string) $row->reference_table,
                        'id' => (int) $row->reference_id,
                        'status' => $outcome['status'],
                    ]);
                }
            }
        });

        return $summary;
    }

    /** @return array{status: string, bytes: int} */
    private function cleanupOne(int $ledgerId, bool $apply): array
    {
        try {
            return DB::transaction(function () use ($ledgerId, $apply): array {
                $snapshot = DB::table('phr_blob_migrations')->where('id', $ledgerId)->first();
                if ($snapshot === null || $snapshot->legacy_deleted_at !== null) {
                    return ['status' => 'stale_ledger', 'bytes' => 0];
                }

                $definition = self::ARTIFACTS[(string) $snapshot->artifact_class] ?? null;
                if ($definition === null
                    || $definition['disk'] !== (string) $snapshot->storage_disk
                    || $definition['table'] !== (string) $snapshot->reference_table
                    || $definition['column'] !== (string) $snapshot->reference_column) {
                    return ['status' => 'invalid_ledger', 'bytes' => (int) $snapshot->source_size_bytes];
                }

                // The table and column are selected exclusively from the fixed map,
                // never from mutable ledger values.
                $reference = DB::table($definition['table'])
                    ->where('id', (int) $snapshot->reference_id)
                    ->lockForUpdate()
                    ->first(['id', 'patient_id', $definition['column']]);
                $ledger = DB::table('phr_blob_migrations')
                    ->where('id', $ledgerId)
                    ->lockForUpdate()
                    ->first();
                if ($ledger === null || $ledger->legacy_deleted_at !== null) {
                    return ['status' => 'stale_ledger', 'bytes' => 0];
                }

                if ((string) $ledger->artifact_class !== (string) $snapshot->artifact_class
                    || (string) $ledger->storage_disk !== (string) $snapshot->storage_disk
                    || (string) $ledger->reference_table !== (string) $snapshot->reference_table
                    || (int) $ledger->reference_id !== (int) $snapshot->reference_id
                    || (string) $ledger->reference_column !== (string) $snapshot->reference_column
                    || (int) $ledger->patient_id !== (int) $snapshot->patient_id) {
                    return ['status' => 'stale_ledger', 'bytes' => 0];
                }

                $bytes = (int) $ledger->source_size_bytes;
                if ($reference === null
                    || (int) $reference->patient_id !== (int) $ledger->patient_id
                    || (string) $reference->{$definition['column']} !== (string) $ledger->destination_key) {
                    return ['status' => 'stale_reference', 'bytes' => $bytes];
                }
                if (now()->lt($ledger->retain_until)) {
                    return ['status' => 'retained', 'bytes' => $bytes];
                }

                $source = (string) $ledger->source_key;
                $destination = (string) $ledger->destination_key;
                if (! $this->validStoredKey($source)
                    || ! $this->validStoredKey($destination)
                    || str_starts_with($source, 'patients/')
                    || ! $this->legacyPatientMatches($source, (int) $ledger->patient_id)
                    || ! str_starts_with($destination, 'patients/'.(int) $ledger->patient_id.'/')) {
                    return ['status' => 'invalid_ledger', 'bytes' => $bytes];
                }

                $expected = ['size' => $bytes, 'sha256' => (string) $ledger->source_sha256];
                $storage = Storage::disk($definition['disk']);
                if ($this->fingerprint($storage, $destination) !== $expected) {
                    return ['status' => 'destination_mismatch', 'bytes' => $bytes];
                }

                try {
                    $sourceExists = $storage->exists($source);
                } catch (Throwable) {
                    return ['status' => 'source_unreadable', 'bytes' => $bytes];
                }
                $sourceFingerprint = $this->fingerprint($storage, $source);
                if ($sourceFingerprint === null) {
                    if ($sourceExists) {
                        return ['status' => 'source_unreadable', 'bytes' => $bytes];
                    }
                    if ($apply) {
                        $this->markDeleted($ledgerId);
                    }

                    return ['status' => $apply ? 'already_deleted' : 'planned', 'bytes' => $bytes];
                }
                if ($sourceFingerprint !== $expected) {
                    return ['status' => 'source_mismatch', 'bytes' => $bytes];
                }
                if (! $apply) {
                    return ['status' => 'planned', 'bytes' => $bytes];
                }

                if (! $storage->delete($source) || $storage->exists($source)) {
                    return ['status' => 'delete_failed', 'bytes' => $bytes];
                }
                $this->markDeleted($ledgerId);

                return ['status' => 'deleted', 'bytes' => $bytes];
            }, 3);
        } catch (Throwable) {
            return ['status' => 'cleanup_failed', 'bytes' => 0];
        }
    }

    private function markDeleted(int $ledgerId): void
    {
        $updated = DB::table('phr_blob_migrations')
            ->where('id', $ledgerId)
            ->whereNull('legacy_deleted_at')
            ->update(['legacy_deleted_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new \RuntimeException('Migration ledger changed during cleanup.');
        }
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
}
