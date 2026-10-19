<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * The catalogue parsed from a package's HTML dictionary. An empty catalogue means
 * the structure was not recognized; intake records that as a finding.
 */
final readonly class EhiDictionary
{
    /** @var array<string, EhiDictionaryTable> keyed and sorted by table name (byte order) */
    public array $tables;

    /** @var array<string, string|false> ASCII-upper-cased name => exact name, or false when two names fold together */
    private array $folded;

    /**
     * @param  array<string, EhiDictionaryTable>|list<EhiDictionaryTable>  $tables
     */
    public function __construct(
        array $tables,
        public string $readerVersion,
        /** Documents parsed. */
        public int $documents = 0,
        /** Documents refused for size. */
        public int $refusedDocuments = 0,
        /** Documents libxml could not load at all. */
        public int $unparseableDocuments = 0,
        /** Tables documented differently by two documents; the first one wins. */
        public int $conflictingTables = 0,
        /** Column or key grids with no recognizable table name. */
        public int $unnamedGrids = 0,
        /** Primary-key sections or key columns seen, parsed or not. */
        public int $primaryKeyMarkers = 0,
    ) {
        $byName = [];
        foreach ($tables as $table) {
            $byName[$table->name] = $table;
        }
        ksort($byName, SORT_STRING);
        $this->tables = $byName;
        $folded = [];
        foreach (array_keys($byName) as $name) {
            $key = strtoupper((string) $name);
            $folded[$key] = isset($folded[$key]) ? false : (string) $name;
        }
        $this->folded = $folded;
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    /**
     * The exact name first; otherwise the one table whose name equals it under
     * ASCII case folding (headings like "Table: Patient" document PATIENT.tsv).
     * Two documented names that fold together are ambiguous and match neither.
     */
    public function table(string $name): ?EhiDictionaryTable
    {
        if (isset($this->tables[$name])) {
            return $this->tables[$name];
        }
        $exact = $this->folded[strtoupper($name)] ?? false;

        return $exact === false ? null : $this->tables[$exact];
    }

    public function columnCount(): int
    {
        return array_sum(array_map(static fn (EhiDictionaryTable $table): int => count($table->columns), $this->tables));
    }

    public function primaryKeyTableCount(): int
    {
        return count(array_filter($this->tables, static fn (EhiDictionaryTable $table): bool => $table->primaryKey !== []));
    }

    /**
     * sha256 of the canonical catalogue: tables sorted by name, columns and key
     * columns in dictionary order. Reader counters and version are excluded, so
     * the same documentation parsed the same way always converges.
     */
    public function fingerprint(): string
    {
        $canonical = array_map(static fn (EhiDictionaryTable $table): array => $table->toArray(), array_values($this->tables));

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
