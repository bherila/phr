<?php

namespace App\Support\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fluent declaration of every column in this app that can hold a storage key.
 *
 * A stored object is garbage only when NO mapped column references it. That is set
 * membership, not reference counting — one object may legitimately be referenced from
 * several tables at once (in bwh-php a `tax_docs/...` key is reachable from both
 * `fin_tax_documents.s3_path` and `genai_import_jobs.s3_path`), and it is prunable only
 * once every one of them has let go.
 *
 * The map is deliberately per-app rather than shared: each app owns a disjoint storage
 * root, so mixing them would let one app's pruner reason about the other's data.
 *
 * Two properties this class is responsible for, both of which have teeth:
 *
 * 1. **Soft-deleted rows still count as references.** Queries here go through
 *    DB::table(), not Eloquent, so the SoftDeletes global scope never applies and
 *    trashed rows are included automatically. That is the safe default and it is not
 *    configurable — a soft-deleted row is recoverable, so destroying its bytes would
 *    turn a reversible delete into a permanent one.
 *
 * 2. **Unmapped columns are a test failure, not silent data loss.** Anything holding a
 *    storage key must be listed via from(); anything that merely looks like one must be
 *    listed via ignoring(). BlobReferencesCoverageTest walks the live schema and fails
 *    if a column is in neither list, so adding a file-bearing table cannot quietly widen
 *    what the pruner deletes.
 */
class BlobReferences
{
    /** @var list<BlobReference> */
    private array $references = [];

    /** @var list<string> */
    private array $ignored = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Declare a column that holds a storage key.
     */
    public function from(string $table, string $column): self
    {
        $this->references[] = new BlobReference($table, $column);

        return $this;
    }

    /**
     * Mark the most recently declared column as holding a key PREFIX rather than a key.
     *
     * Everything beneath such a prefix is considered referenced. `phr_dicom_uploads.r2_prefix`
     * is the case in point: it names an upload's directory, not a single object.
     */
    public function asPrefix(): self
    {
        $last = array_key_last($this->references);

        if ($last === null) {
            throw new \LogicException('asPrefix() must follow a from() call.');
        }

        $reference = $this->references[$last];
        $this->references[$last] = new BlobReference(
            $reference->table,
            $reference->column,
            isPrefix: true,
            conditions: $reference->conditions,
        );

        return $this;
    }

    /**
     * Restrict the most recently declared reference to rows matching a value.
     *
     * This is intentionally equality-only. A reference map should be declarative and
     * auditable, not an escape hatch for arbitrary SQL. DICOM upload prefixes use this
     * to protect bytes only while an upload is pending; processed and failed uploads are
     * protected by their per-file rows and must not make leftovers immortal.
     */
    public function where(string $column, string|int|float|bool|null $value): self
    {
        $last = array_key_last($this->references);

        if ($last === null) {
            throw new \LogicException('where() must follow a from() call.');
        }

        $reference = $this->references[$last];
        $this->references[$last] = new BlobReference(
            $reference->table,
            $reference->column,
            isPrefix: $reference->isPrefix,
            conditions: [...$reference->conditions, $column => $value],
        );

        return $this;
    }

    /**
     * Record a column that resembles a storage key but is not one.
     *
     * Exists so the coverage test can tell "considered and excluded" apart from
     * "nobody noticed". State the reason — the next person needs it more than you do.
     */
    public function ignoring(string $table, string $column, string $because): self
    {
        $this->ignored[] = $table.'.'.$column;

        return $this;
    }

    /** @return list<BlobReference> */
    public function references(): array
    {
        return $this->references;
    }

    /** @return list<string> */
    public function ignoredColumns(): array
    {
        return $this->ignored;
    }

    /**
     * Every exact storage key referenced by any mapped column.
     *
     * Returned as an array keyed by storage key so membership tests are O(1) — these
     * sets run to tens of thousands of entries.
     *
     * @return array<string, true>
     */
    public function referencedKeys(): array
    {
        $keys = [];

        foreach ($this->references as $reference) {
            if ($reference->isPrefix || ! $this->exists($reference)) {
                continue;
            }

            $query = DB::table($reference->table)
                ->select($reference->column)
                ->whereNotNull($reference->column)
                ->where($reference->column, '!=', '');
            $this->applyConditions($query, $reference)
                ->orderBy($reference->column)
                ->chunk(5000, function ($rows) use ($reference, &$keys): void {
                    foreach ($rows as $row) {
                        $keys[(string) $row->{$reference->column}] = true;
                    }
                });
        }

        return $keys;
    }

    /**
     * Every referenced key PREFIX. Any object beneath one of these is referenced.
     *
     * @return list<string>
     */
    public function referencedPrefixes(): array
    {
        $prefixes = [];

        foreach ($this->references as $reference) {
            if (! $reference->isPrefix || ! $this->exists($reference)) {
                continue;
            }

            $query = DB::table($reference->table)
                ->whereNotNull($reference->column)
                ->where($reference->column, '!=', '');

            foreach ($this->applyConditions($query, $reference)->pluck($reference->column) as $prefix) {
                $prefixes[] = rtrim((string) $prefix, '/').'/';
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * Whether a storage key is referenced by anything at all.
     *
     * @param  array<string, true>  $keys
     * @param  list<string>  $prefixes
     */
    public static function covers(string $storageKey, array $keys, array $prefixes): bool
    {
        if (isset($keys[$storageKey])) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($storageKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tolerate a column that has not been migrated yet rather than aborting the sweep.
     *
     * Erring toward "referenced" is the safe direction: a missing table contributes no
     * keys, so anything it would have protected simply is not seen as garbage.
     */
    private function exists(BlobReference $reference): bool
    {
        if (! Schema::hasTable($reference->table) || ! Schema::hasColumn($reference->table, $reference->column)) {
            return false;
        }

        foreach (array_keys($reference->conditions) as $column) {
            if (! Schema::hasColumn($reference->table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function applyConditions(Builder $query, BlobReference $reference): Builder
    {
        foreach ($reference->conditions as $column => $value) {
            $query->where($column, $value);
        }

        return $query;
    }
}
