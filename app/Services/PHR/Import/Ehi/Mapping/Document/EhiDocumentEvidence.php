<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Models\PhrEhiBlob;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiRevision;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use LogicException;

/**
 * One package's document evidence, indexed once, so a note costs no query of its own.
 *
 * Rows come only from EhiMappingContext::lazyRevisions(), so only revisions this
 * package supplied are visible. Each joined table is streamed once, a page at a
 * time: column value => revision ids (for a grouped table, column value => group
 * value => revision ids), each list in the context's deterministic order.
 *
 * A row-sized join table (encounters, contacts, documents, patient links) is kept,
 * without its empty cells, which every reader treats like absent ones. Note text
 * lines are not kept: fetch() reads them by id, with the lines planNoteText() says the
 * next notes will want, at most the context's chunk at a time and each handed out
 * once, so a page of lines is one read and no more than a page is held.
 *
 * An entity with more than one revision in the package is ambiguous; it stays indexed
 * so dependants can see it and refuse to guess. Note/media members are the package's
 * own manifest rows, with their blobs read once.
 */
final class EhiDocumentEvidence
{
    /** @var array<int, true> revision ids whose entity has another revision in this package */
    private array $ambiguous = [];

    /** @var array<string, array{column: string, group: string|null}> table => what it is indexed by */
    private array $spec = [];

    /** @var array<string, array<array-key, list<int>>> table => value => revision ids */
    private array $index = [];

    /** @var array<string, array<array-key, array<array-key, list<int>>>> table => value => group => revision ids */
    private array $grouped = [];

    /** @var list<PhrEhiPackageMember> */
    private array $members = [];

    /** @var array<string, list<PhrEhiPackageMember>> exact basename => members */
    private array $membersByBasename = [];

    /** @var array<int, PhrEhiBlob> blob id => the members' blobs */
    private array $blobs = [];

    /** @var array<int, PhrEhiRevision> kept rows of the tables without a group */
    private array $kept = [];

    /** @var list<list<int>> line ids the next notes will want, in note order */
    private array $plannedReads = [];

    /** @var array<int, PhrEhiRevision> lines read ahead, until each is handed out */
    private array $readAhead = [];

    private function __construct(private readonly EhiMappingContext $context) {}

    /**
     * @param  array<string, array{0: string, 1?: string}>  $indexes  table => [column, optional group column]
     */
    public static function load(EhiMappingContext $context, array $indexes): self
    {
        $evidence = new self($context);
        foreach ($indexes as $table => $spec) {
            $evidence->indexTable($table, $spec[0], $spec[1] ?? null);
        }

        $context->budget->assertPatient($context->patient->id);
        $evidence->members = array_values(PhrEhiPackageMember::query()
            ->where('package_id', $context->package->id)
            ->where('patient_id', $context->patient->id)
            ->whereIn('kind', [EhiMemberKind::NOTE, EhiMemberKind::MEDIA])
            ->limit((int) config('phr.ehi_preview_max_rows') + 1)
            ->orderBy('path')
            ->orderBy('id')
            ->get()
            ->all());
        $context->budget->charge(count($evidence->members), 0);
        foreach ($evidence->members as $member) {
            $evidence->membersByBasename[self::basename($member->path)][] = $member;
        }
        foreach (array_chunk(array_values(array_unique(array_map(static fn (PhrEhiPackageMember $member): int => (int) $member->blob_id, $evidence->members))), 500) as $page) {
            foreach (PhrEhiBlob::query()->where('patient_id', $context->patient->id)->whereIn('id', $page)->get() as $blob) {
                $evidence->blobs[$blob->id] = $blob;
            }
        }

        return $evidence;
    }

    /**
     * Kept rows whose indexed column holds exactly this raw value.
     *
     * @return list<PhrEhiRevision>
     */
    public function where(string $table, string $column, string $value): array
    {
        $this->assertIndexed($table, $column, false);
        $ids = $this->index[$table][$value] ?? [];
        $this->context->budget->charge(count($ids), 0);

        return array_map(fn (int $id): PhrEhiRevision => $this->kept[$id], $ids);
    }

    /** The member's blob, if the package's manifest names one this patient holds. */
    public function blobOf(PhrEhiPackageMember $member): ?PhrEhiBlob
    {
        return $this->blobs[(int) $member->blob_id] ?? null;
    }

    /**
     * The line ids the next notes will read first, in the order they will be read.
     * Replaces any earlier plan, and drops what was read ahead for a note that never
     * asked for it (one retained before reading its text).
     *
     * @param  list<list<int>>  $reads
     */
    public function planNoteText(array $reads): void
    {
        $this->plannedReads = $reads;
        $this->readAhead = [];
    }

    /**
     * Revision ids of the rows whose indexed column holds this raw value, by the
     * table's group column (a null group reads as ''), groups in first-row order.
     *
     * @return array<array-key, list<int>>
     */
    public function groups(string $table, string $column, string $value): array
    {
        $this->assertIndexed($table, $column, true);

        return $this->grouped[$table][$value] ?? [];
    }

    /**
     * Every revision id indexed for a table.
     *
     * @return array<int, true>
     */
    public function indexedIds(string $table): array
    {
        $ids = [];
        foreach ($this->index[$table] ?? [] as $list) {
            foreach ($list as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * Text lines by id, in the order the ids were given, each handed out once.
     *
     * @param  list<int>  $ids
     * @return list<PhrEhiRevision>
     */
    public function fetch(array $ids): array
    {
        $this->context->budget->charge(count($ids), 0);
        $missing = array_values(array_filter($ids, fn (int $id): bool => ! isset($this->readAhead[$id])));
        if ($missing !== []) {
            $this->read($missing);
        }
        $rows = [];
        $bytes = 0;
        foreach ($ids as $id) {
            if (isset($this->readAhead[$id])) {
                $bytes += strlen((string) $this->readAhead[$id]->getRawOriginal('values_json'));
                $rows[] = $this->readAhead[$id];
                unset($this->readAhead[$id]);
            }
        }
        $this->context->budget->charge(count($ids), $bytes);

        return $rows;
    }

    /**
     * Read these lines, and as many planned ones as fit with them in one chunk.
     *
     * @param  list<int>  $ids
     */
    private function read(array $ids): void
    {
        $this->context->budget->assertPatient($this->context->patient->id);
        $chunk = max(1, $this->context->chunk);
        $wanted = array_flip($ids);
        while ($this->plannedReads !== [] && count($this->readAhead) + count($wanted) + count($this->plannedReads[0]) <= $chunk) {
            $wanted += array_flip((array) array_shift($this->plannedReads));
        }
        foreach (array_chunk(array_keys($wanted), $chunk) as $page) {
            foreach (PhrEhiRevision::query()
                ->select(['id', 'entity_id', 'content_digest', 'values_json'])
                ->where('patient_id', $this->context->patient->id)
                ->whereIn('id', $page)
                ->get() as $revision) {
                $this->readAhead[$revision->id] = $revision;
            }
        }
    }

    public function isAmbiguous(PhrEhiRevision $revision): bool
    {
        return isset($this->ambiguous[$revision->id]);
    }

    /** @return list<PhrEhiPackageMember> */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * Note/media members whose file name equals a documented file-name reference
     * exactly (byte-for-byte, case-sensitive). A reference carrying a path
     * separator never matches: the spec documents a bare file name.
     *
     * @return list<PhrEhiPackageMember>
     */
    public function membersNamed(string $fileName): array
    {
        if ($fileName === '' || strpbrk($fileName, '/\\') !== false) {
            return [];
        }

        return $this->membersByBasename[$fileName] ?? [];
    }

    /**
     * Stream one table and keep only its join value, optional group value, and
     * revision ids. Revisions of one entity are adjacent in the stream, so more
     * than one in a row marks every one of them ambiguous.
     */
    private function indexTable(string $table, string $column, ?string $group): void
    {
        $this->spec[$table] = ['column' => $column, 'group' => $group];
        $entityId = null;
        $entityIds = [];
        foreach ($this->context->lazyRevisions($table) as $revision) {
            if ($revision->entity_id !== $entityId) {
                $this->markAmbiguous($entityIds);
                $entityId = $revision->entity_id;
                $entityIds = [];
            }
            $entityIds[] = $revision->id;

            $value = EhiDocumentValues::cell($revision, $column);
            if ($value === null) {
                continue;
            }
            if ($group === null) {
                $this->index[$table][$value][] = $revision->id;
                $this->kept[$revision->id] = self::withoutEmptyCells($revision);
            } else {
                $this->grouped[$table][$value][EhiDocumentValues::cell($revision, $group) ?? ''][] = $revision->id;
            }
        }
        $this->markAmbiguous($entityIds);
    }

    /** Every reader goes through EhiDocumentValues::cell(), which reads an empty cell as absent. */
    private static function withoutEmptyCells(PhrEhiRevision $revision): PhrEhiRevision
    {
        $revision->values_json = array_filter((array) $revision->values_json, static fn (mixed $value): bool => $value !== null && $value !== '');

        return $revision;
    }

    /** @param  list<int>  $ids  every revision of one entity */
    private function markAmbiguous(array $ids): void
    {
        if (count($ids) > 1) {
            foreach ($ids as $id) {
                $this->ambiguous[$id] = true;
            }
        }
    }

    private function assertIndexed(string $table, string $column, bool $grouped): void
    {
        $spec = $this->spec[$table] ?? null;
        if ($spec === null || $spec['column'] !== $column || ($spec['group'] !== null) !== $grouped) {
            throw new LogicException("EHI document evidence does not index {$table}.{$column} that way.");
        }
    }

    private static function basename(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
    }
}
