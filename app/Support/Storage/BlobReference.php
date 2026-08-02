<?php

namespace App\Support\Storage;

/**
 * One database column that can hold a storage key.
 *
 * @see BlobReferences for the fluent builder that collects these.
 */
class BlobReference
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly bool $isPrefix = false,
    ) {}

    public function label(): string
    {
        return $this->table.'.'.$this->column;
    }
}
