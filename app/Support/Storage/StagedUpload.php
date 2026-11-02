<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * An upload that passes through PHP in chunks, on its way to one object.
 *
 * A local disk appends each chunk to the file in place. An object cannot be appended to,
 * so on an object store the chunks collect in a local spool at the same key, and publish()
 * turns the finished spool into the object. The spool is not storage: its bytes are on
 * their way somewhere, and only the upload's own row refers to them.
 */
final class StagedUpload
{
    public const string SPOOL_DISK = 'phr_upload_spool';

    /** Start an empty upload that append() can continue. */
    public static function create(string $diskName, string $path): bool
    {
        return self::chunkDisk($diskName)->put($path, '');
    }

    /**
     * Append one chunk. Call it with the upload's row locked: see StagedChunkAppender.
     *
     * @return StagedChunkAppender::APPENDED|StagedChunkAppender::STATE_MISMATCH|StagedChunkAppender::STORAGE_FAILED
     */
    public static function append(string $diskName, string $path, string $chunkPath, int $offset, int $chunkSize): string
    {
        return StagedChunkAppender::append(self::chunkDisk($diskName)->path($path), $chunkPath, $offset, $chunkSize);
    }

    /**
     * Turn a finished spool into the object, removing the spool only once the object is
     * whole. Nothing to do on a local disk, or when it was already published: a retry
     * after a failed intake finds no spool and carries on with the object.
     *
     * Run this outside any lock, because it can move gigabytes. A caller that needs the
     * patient to still exist afterwards must check under the guard, and delete on failure.
     *
     * @return bool whether this call wrote the object
     *
     * @throws RuntimeException when the object was not stored whole; the spool is kept
     */
    public static function publish(string $diskName, string $path, int $bytes): bool
    {
        $spool = Storage::disk(self::SPOOL_DISK);
        if (LocalObjectFile::isLocal(Storage::disk($diskName)) || ! $spool->exists($path)) {
            return false;
        }

        $stream = $spool->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('A finished upload could not be read.');
        }
        try {
            $disk = Storage::disk($diskName);
            if (! $disk->writeStream($path, $stream) || $disk->size($path) !== $bytes) {
                throw new RuntimeException('A finished upload could not be stored whole.');
            }
        } finally {
            fclose($stream);
        }
        $spool->delete($path);

        return true;
    }

    /** Remove a spool that will never be published. True if nothing is left of it. */
    public static function discard(string $path): bool
    {
        return Storage::disk(self::SPOOL_DISK)->delete($path);
    }

    private static function chunkDisk(string $diskName): Filesystem
    {
        $disk = Storage::disk($diskName);

        return LocalObjectFile::isLocal($disk) ? $disk : Storage::disk(self::SPOOL_DISK);
    }
}
