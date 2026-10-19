<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * A file entry that passed EhiArchiveGuard. `path` is the normalized relative path
 * (forward slashes, NFC, no leading/trailing slash, no empty/dot segments).
 */
final readonly class EhiValidatedMember
{
    public function __construct(
        public string $path,
        public EhiEntry $entry,
    ) {}
}
