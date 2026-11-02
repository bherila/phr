<?php

declare(strict_types=1);

namespace App\Support\Storage;

use InvalidArgumentException;

/**
 * How one file divides into parts. Every S3-compatible store sets the same two limits: each
 * part but the last is at least 5 MiB, and an upload has at most 10,000 parts. Part sizes are
 * whole MiB, so a file only ever has one plan.
 */
final readonly class MultipartPlan
{
    public const int MIN_PART_BYTES = 5 * 1024 * 1024;

    public const int MAX_PARTS = 10_000;

    private const int MEBIBYTE = 1024 * 1024;

    public function __construct(
        public int $fileBytes,
        public int $partBytes,
    ) {
        if ($fileBytes < 1 || $partBytes < self::MIN_PART_BYTES) {
            throw new InvalidArgumentException('A multipart upload needs a file and a part of at least 5 MiB.');
        }
        if ($this->partCount() > self::MAX_PARTS) {
            throw new InvalidArgumentException('A multipart upload has at most 10,000 parts.');
        }
    }

    /** Use the preferred part size where the limits allow it, and the next size up where not. */
    public static function forFile(int $fileBytes, int $preferredPartBytes): self
    {
        $needed = max($preferredPartBytes, self::MIN_PART_BYTES, (int) ceil($fileBytes / self::MAX_PARTS));

        return new self($fileBytes, (int) ceil($needed / self::MEBIBYTE) * self::MEBIBYTE);
    }

    public function partCount(): int
    {
        return (int) ceil($this->fileBytes / $this->partBytes);
    }

    public function holds(int $partNumber): bool
    {
        return $partNumber >= 1 && $partNumber <= $this->partCount();
    }

    /** The exact length of one part: the part size, or what is left for the last. */
    public function bytesOf(int $partNumber): int
    {
        if (! $this->holds($partNumber)) {
            throw new InvalidArgumentException('No such part.');
        }

        return min($this->partBytes, $this->fileBytes - ($partNumber - 1) * $this->partBytes);
    }

    /** Bytes in every part before this one. */
    public function bytesBefore(int $partNumber): int
    {
        return ($partNumber - 1) * $this->partBytes;
    }
}
