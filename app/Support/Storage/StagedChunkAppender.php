<?php

declare(strict_types=1);

namespace App\Support\Storage;

/**
 * Appends one chunk of a resumable upload to its staged file.
 *
 * Call it with the upload row locked: the row's acknowledged byte count
 * (`$offset`) is the authority on what was received. Bytes past it belong to an
 * append whose row update never committed (a rolled back or retried
 * transaction), so they are dropped before the length check and a resend works.
 */
final class StagedChunkAppender
{
    public const APPENDED = 'appended';

    public const STATE_MISMATCH = 'state_mismatch';

    public const STORAGE_FAILED = 'storage_failed';

    /**
     * @return self::APPENDED|self::STATE_MISMATCH|self::STORAGE_FAILED
     */
    public static function append(string $targetPath, string $chunkPath, int $offset, int $chunkSize): string
    {
        clearstatcache(true, $targetPath);
        $staged = @filesize($targetPath);
        if (is_int($staged) && $staged > $offset) {
            self::truncate($targetPath, $offset);
            clearstatcache(true, $targetPath);
            $staged = @filesize($targetPath);
        }
        if ($staged !== $offset) {
            return self::STATE_MISMATCH;
        }

        $source = @fopen($chunkPath, 'rb');
        $target = @fopen($targetPath, 'ab');
        try {
            if (! is_resource($source) || ! is_resource($target)) {
                return self::STORAGE_FAILED;
            }
            $written = stream_copy_to_stream($source, $target);
            $flushed = fflush($target);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
        }
        if ($written !== $chunkSize || ! $flushed) {
            // Cut back to the acknowledged length so the client can resend.
            self::truncate($targetPath, $offset);

            return self::STORAGE_FAILED;
        }

        return self::APPENDED;
    }

    private static function truncate(string $path, int $length): void
    {
        $handle = @fopen($path, 'r+b');
        if (is_resource($handle)) {
            ftruncate($handle, $length);
            fclose($handle);
        }
    }
}
