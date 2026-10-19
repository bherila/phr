<?php

namespace App\Services\PHR\Import\Ehi;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Re-reads a stored EHI object and compares it with the size and sha256 its blob
 * row records. Reads are streamed; nothing about the object's content is kept.
 */
final class EhiBlobVerifier
{
    public const string OK = 'ok';

    public const string MISSING = 'missing';

    /** Present, but its size or digest differs from the record. */
    public const string TAMPERED = 'tampered';

    private const int CHUNK_BYTES = 1024 * 1024;

    public function inspect(string $disk, string $path, string $sha256, int $bytes): string
    {
        $filesystem = Storage::disk($disk);
        try {
            if (! $filesystem->exists($path)) {
                return self::MISSING;
            }
            if ($filesystem->size($path) !== $bytes) {
                return self::TAMPERED;
            }
            $stream = $filesystem->readStream($path);
        } catch (Throwable) {
            return self::MISSING;
        }
        if (! is_resource($stream)) {
            return self::MISSING;
        }

        try {
            $hash = hash_init('sha256');
            $read = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    return self::TAMPERED;
                }
                $read += strlen($chunk);
                if ($read > $bytes) {
                    return self::TAMPERED;
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return $read === $bytes && hash_equals($sha256, hash_final($hash)) ? self::OK : self::TAMPERED;
    }
}
