<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * One table as documented by the package's own dictionary. Names and types are
 * the dictionary's text verbatim (whitespace-collapsed); nothing is inferred.
 */
final readonly class EhiDictionaryTable
{
    public function __construct(
        public string $name,
        /** @var list<array{name: string, type: ?string}> in dictionary order */
        public array $columns,
        /** @var list<string> in key order */
        public array $primaryKey,
    ) {}

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_column($this->columns, 'name');
    }

    /** @return array{name: string, columns: list<array{name: string, type: ?string}>, primary_key: list<string>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'columns' => $this->columns, 'primary_key' => $this->primaryKey];
    }
}
