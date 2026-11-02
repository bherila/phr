<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * A stored object as a file on this machine, for the few readers that need a seekable
 * path: ZipArchive, pdftotext, a CSV parser. On a local disk that is the file itself. On
 * an object store the object is streamed to a temporary file, which release() removes.
 *
 * This is the one way to get a path for a stored object. FilesystemAdapter::path() does
 * not fail on an object store: it returns the key, which then reads as a relative path
 * that does not exist. LocalDiskAssumptionsTest keeps other callers out.
 */
final class LocalObjectFile
{
    private function __construct(
        public readonly string $path,
        private readonly bool $temporary,
    ) {}

    public static function isLocal(Filesystem $disk): bool
    {
        return $disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /** @throws RuntimeException when the whole object cannot be brought here */
    public static function open(string $diskName, string $key): self
    {
        $disk = Storage::disk($diskName);
        if (self::isLocal($disk)) {
            return new self($disk->path($key), false);
        }

        $temporary = tempnam((string) (config('phr.object_temp_dir') ?: sys_get_temp_dir()), 'phr-object-');
        if ($temporary === false) {
            throw new RuntimeException('No temporary file could be created for a stored object.');
        }
        $copied = false;
        try {
            $copied = self::streamTo($disk, $key, $temporary);
        } finally {
            if (! $copied) {
                @unlink($temporary);
            }
        }

        return new self($temporary, true);
    }

    /**
     * @template TResult
     *
     * @param  callable(string): TResult  $use
     * @return TResult
     */
    public static function with(string $diskName, string $key, callable $use): mixed
    {
        $file = self::open($diskName, $key);
        try {
            return $use($file->path);
        } finally {
            $file->release();
        }
    }

    public function release(): void
    {
        if ($this->temporary) {
            @unlink($this->path);
        }
    }

    /**
     * An object's first bytes, read to the end of the request rather than the end of the
     * first network packet: a single fread on an object store's stream can return less.
     */
    public static function prefix(string $diskName, string $key, int $bytes): string
    {
        $stream = Storage::disk($diskName)->readStream($key);
        if (! is_resource($stream)) {
            return '';
        }
        try {
            return (string) stream_get_contents($stream, $bytes);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Give the same bytes a second key on one disk without moving them through PHP: a hard
     * link on a local disk, a server-side copy on an object store. False when neither
     * worked, so the caller can stream instead.
     */
    public static function duplicateWithinDisk(string $diskName, string $from, string $to): bool
    {
        $disk = Storage::disk($diskName);
        if (! self::isLocal($disk)) {
            return $disk->copy($from, $to);
        }

        return $disk->makeDirectory(dirname($to)) && @link($disk->path($from), $disk->path($to));
    }

    private static function streamTo(Filesystem $disk, string $key, string $target): bool
    {
        $expected = $disk->size($key);
        $source = $disk->readStream($key);
        $sink = fopen($target, 'wb');
        try {
            if (! is_resource($source) || ! is_resource($sink)) {
                throw new RuntimeException('A stored object could not be opened for reading.');
            }
            $copied = stream_copy_to_stream($source, $sink);
            if ($copied !== $expected || ! fflush($sink)) {
                throw new RuntimeException('A stored object did not arrive whole.');
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        return true;
    }
}
