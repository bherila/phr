<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * One well-formed record. Values are the file's raw strings, never cast: an
 * empty cell is "", and null appears only where the dialect's null marker matched.
 */
final readonly class EhiTsvRow
{
    public function __construct(
        /** Physical (LF-counted, 1-based) line on which the record starts. */
        public int $lineNumber,
        /** Byte offset of the record start within the member. */
        public int $byteOffset,
        /** @var list<string|null> */
        public array $values,
    ) {}
}
