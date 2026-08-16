<?php

namespace App\Support\Storage;

use App\Models\PhrDocument;
use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Compares an external evidence tree with one patient's operational documents.
 *
 * Only SHA-256 membership, counts, byte totals, and internal document ids leave
 * this service. Source paths and stored object keys can contain health information
 * and must never appear in command output or exceptions.
 */
final class PhrSourceReconciliationService
{
    /**
     * @param  list<string>  $extensions
     * @param  callable(array{table: string, id: int, status: string}): void|null  $reporter
     */
    public function run(
        int $patientId,
        string $sourceDirectory,
        array $extensions = [],
        ?callable $reporter = null,
    ): PhrSourceReconciliationSummary {
        $summary = new PhrSourceReconciliationSummary;
        $normalizedExtensions = $this->normalizeExtensions($extensions);
        $sourceHashes = $this->sourceHashes($sourceDirectory, $normalizedExtensions, $summary);
        /** @var array<string, true> $verifiedDocumentHashes */
        $verifiedDocumentHashes = [];

        DB::table('phr_documents')
            ->where('patient_id', $patientId)
            ->whereNotNull('storage_path')
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($normalizedExtensions, $reporter, $sourceHashes, $summary, &$verifiedDocumentHashes): void {
                foreach ($documents as $document) {
                    if (! $this->documentMatchesExtensions($document, $normalizedExtensions)) {
                        continue;
                    }
                    $summary->documents++;
                    $status = $this->documentStatus($document, $sourceHashes, $summary, $verifiedDocumentHashes);
                    if ($reporter !== null) {
                        $reporter([
                            'table' => 'phr_documents',
                            'id' => (int) $document->id,
                            'status' => $status,
                        ]);
                    }
                }
            });

        foreach ($sourceHashes as $hash => $source) {
            if (isset($verifiedDocumentHashes[$hash])) {
                $summary->sourceMatched += $source['count'];
            } else {
                $summary->sourceUnmatched += $source['count'];
            }
        }

        return $summary;
    }

    /**
     * @param  list<string>  $extensions
     * @return array<string, array{count: int, bytes: int}>
     */
    private function sourceHashes(
        string $sourceDirectory,
        array $extensions,
        PhrSourceReconciliationSummary $summary,
    ): array {
        if (is_link($sourceDirectory)) {
            throw new RuntimeException('The source evidence directory must not be a symbolic link.');
        }
        $root = realpath($sourceDirectory);
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException('The source evidence directory is not readable.');
        }

        /** @var array<string, array{count: int, bytes: int}> $hashes */
        $hashes = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Source evidence must not contain symbolic links.');
                }
                if (! $file->isFile()) {
                    continue;
                }
                if ($extensions !== []
                    && ! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $fingerprint = $this->localFingerprint($file->getPathname());
                $summary->sourceFiles++;
                $summary->sourceBytes += $fingerprint['size'];
                $hashes[$fingerprint['sha256']] ??= ['count' => 0, 'bytes' => 0];
                $hashes[$fingerprint['sha256']]['count']++;
                $hashes[$fingerprint['sha256']]['bytes'] += $fingerprint['size'];
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('The source evidence scan failed.');
        }

        return $hashes;
    }

    /** @param list<string> $extensions
     * @return list<string>
     */
    private function normalizeExtensions(array $extensions): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (string $extension): string => strtolower(ltrim($extension, '.')),
            $extensions,
        )));
        if (in_array('', $normalized, true)) {
            throw new RuntimeException('Source extensions must not be empty.');
        }

        return $normalized;
    }

    /** @param list<string> $extensions */
    private function documentMatchesExtensions(object $document, array $extensions): bool
    {
        if ($extensions === []) {
            return true;
        }

        $filename = is_string($document->original_filename) && $document->original_filename !== ''
            ? $document->original_filename
            : (string) $document->storage_path;

        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $extensions, true);
    }

    /**
     * @param  array<string, array{count: int, bytes: int}>  $sourceHashes
     * @param  array<string, true>  $verifiedDocumentHashes
     */
    private function documentStatus(
        object $document,
        array $sourceHashes,
        PhrSourceReconciliationSummary $summary,
        array &$verifiedDocumentHashes,
    ): string {
        if ((string) $document->storage_disk !== PhrDocument::STORAGE_DISK) {
            $summary->documentFailures++;

            return 'invalid_reference';
        }

        $key = (string) $document->storage_path;
        if (! $this->validStoredKey($key)) {
            $summary->documentFailures++;

            return 'invalid_reference';
        }

        $fingerprint = $this->storageFingerprint(Storage::disk(PhrDocument::STORAGE_DISK), $key);
        if ($fingerprint === null) {
            $summary->documentFailures++;

            return 'missing_blob';
        }
        $summary->documentBytes += $fingerprint['size'];

        $storedHash = is_string($document->file_hash) ? strtolower($document->file_hash) : null;
        if ((int) $document->byte_size !== $fingerprint['size']
            || $storedHash === null
            || preg_match('/^[a-f0-9]{64}$/', $storedHash) !== 1
            || ! hash_equals($storedHash, $fingerprint['sha256'])) {
            $summary->documentFailures++;

            return 'metadata_mismatch';
        }

        $verifiedDocumentHashes[$fingerprint['sha256']] = true;
        if (isset($sourceHashes[$fingerprint['sha256']])) {
            $summary->documentsMatched++;

            return 'matched';
        }

        $summary->documentsUnmatched++;

        return 'unmatched';
    }

    /** @return array{size: int, sha256: string} */
    private function localFingerprint(string $path): array
    {
        $stream = @fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('A source evidence file could not be read.');
        }

        try {
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $stream);

            return ['size' => $bytes, 'sha256' => hash_final($context)];
        } finally {
            fclose($stream);
        }
    }

    /** @return array{size: int, sha256: string}|null */
    private function storageFingerprint(Filesystem $disk, string $key): ?array
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
}
