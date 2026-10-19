<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * One entry as declared by a package source, before validation. `declaredBytes`
 * is what the container claims; copying re-measures and must agree.
 */
final readonly class EhiEntry
{
    public function __construct(
        public string $originalPath,
        public int $declaredBytes,
        public bool $isDirectory = false,
        public bool $isSymlink = false,
        public bool $isSpecial = false,
        public bool $isEncrypted = false,
        public ?int $compressedBytes = null,
        /** Opaque per-source handle (ZIP index, absolute file path). */
        public int|string|null $handle = null,
    ) {}
}
