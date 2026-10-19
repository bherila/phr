<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

/**
 * Storage objects a mapping's apply() published during one review.
 *
 * The review service creates one per review(), outside its transaction (so a
 * retried transaction adds to the same set), and once that transaction has
 * committed or rolled back removes every object no committed row references.
 * A mapping records an object as soon as it is written, before the row that
 * will reference it: a rollback after that point would otherwise orphan it.
 */
final class EhiPublishedObjects
{
    /** @var array<string, array{disk: string, path: string}> */
    private array $objects = [];

    public function add(string $disk, string $path): void
    {
        $this->objects[$disk."\0".$path] = ['disk' => $disk, 'path' => $path];
    }

    /** @return list<array{disk: string, path: string}> */
    public function all(): array
    {
        return array_values($this->objects);
    }
}
