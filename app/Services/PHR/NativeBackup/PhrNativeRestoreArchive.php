<?php

namespace App\Services\PHR\NativeBackup;

use Generator;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * A validated, private local copy of one phr-native-v1 archive.
 *
 * Record streams are reopened for each pass; large artifact bytes never enter a
 * PHP string. The local copy is deleted with this object.
 */
final class PhrNativeRestoreArchive
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public readonly string $localPath,
        public readonly array $manifest,
        public readonly int $fileSize,
        public readonly string $sha256,
        private readonly int $maxRecordBytes,
    ) {}

    public function __destruct()
    {
        @unlink($this->localPath);
    }

    /** @return Generator<int, array<string, mixed>> */
    public function records(string $table): Generator
    {
        $entry = $this->manifest['tables'][$table] ?? null;
        if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
            throw new NativeRestoreException('invalid_archive');
        }

        $zip = $this->open();
        $stream = $zip->getStream($entry['path']);
        if (! is_resource($stream)) {
            $zip->close();
            throw new NativeRestoreException('invalid_archive');
        }

        try {
            while (($line = fgets($stream, $this->maxRecordBytes + 2)) !== false) {
                if (strlen($line) > $this->maxRecordBytes || (! str_ends_with($line, "\n") && ! feof($stream))) {
                    throw new NativeRestoreException('record_size_limit');
                }
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new NativeRestoreException('invalid_archive');
                }
                yield $decoded;
            }
            if (! feof($stream)) {
                throw new NativeRestoreException('invalid_archive');
            }
        } catch (NativeRestoreException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new NativeRestoreException('invalid_archive');
        } finally {
            fclose($stream);
            $zip->close();
        }
    }

    public function copyArtifactTo(string $path, string $disk, string $targetPath): bool
    {
        $zip = $this->open();
        $stream = $zip->getStream($path);
        if (! is_resource($stream)) {
            $zip->close();
            throw new NativeRestoreException('invalid_archive');
        }

        try {
            return Storage::disk($disk)->put($targetPath, $stream);
        } finally {
            fclose($stream);
            $zip->close();
        }
    }

    private function open(): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($this->localPath, ZipArchive::RDONLY) !== true) {
            throw new NativeRestoreException('invalid_archive');
        }

        return $zip;
    }
}
