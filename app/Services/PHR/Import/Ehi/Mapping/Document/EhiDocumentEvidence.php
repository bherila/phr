<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiRevision;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\ResourceByteSize;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One package's document evidence, indexed compactly and read back on demand.
 *
 * Rows come only from EhiMappingContext::lazyRevisions(), so only revisions this
 * package supplied are visible. Each joined table is streamed once, a page at a
 * time, and only its join value and revision ids are kept: column value =>
 * revision ids (for a grouped table, column value => group value => revision
 * ids), each list in the context's deterministic order. Rows themselves (note
 * text lines included) are read back by id only when a dependant is drafted,
 * in pages of at most the context's chunk. An entity with more than one revision
 * in the package is ambiguous; it stays indexed so dependants can see it and
 * refuse to guess. Note/media members are the package's own manifest rows.
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

        return $evidence;
    }

    /**
     * Rows whose indexed column holds exactly this raw value, read back by id.
     *
     * @return list<PhrEhiRevision>
     */
    public function where(string $table, string $column, string $value): array
    {
        $this->assertIndexed($table, $column, false);

        return $this->fetch($this->index[$table][$value] ?? []);
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
     * Revisions by id, read in pages of at most the context's chunk, returned in
     * the order the ids were given.
     *
     * @param  list<int>  $ids
     * @return list<PhrEhiRevision>
     */
    public function fetch(array $ids): array
    {
        $this->context->budget->charge(count($ids), 0);
        $this->context->budget->assertPatient($this->context->patient->id);
        $byId = [];
        $bytes = 0;
        foreach (array_chunk($ids, max(1, $this->context->chunk)) as $page) {
            $length = (new ResourceByteSize)->column('values_json');
            $bytes += (int) DB::table('phr_ehi_revisions')->where('patient_id', $this->context->patient->id)->whereIn('id', $page)->sum(DB::raw($length));
            $this->context->budget->charge(count($ids), $bytes);
            foreach (PhrEhiRevision::query()
                ->select(['id', 'entity_id', 'content_digest', 'values_json'])
                ->where('patient_id', $this->context->patient->id)
                ->whereIn('id', $page)
                ->get() as $revision) {
                $byId[$revision->id] = $revision;
            }
        }
        $rows = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            }
        }

        return $rows;
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
            } else {
                $this->grouped[$table][$value][EhiDocumentValues::cell($revision, $group) ?? ''][] = $revision->id;
            }
        }
        $this->markAmbiguous($entityIds);
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
