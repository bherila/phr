<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Document;

use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiRevision;
use App\Services\PHR\Import\Ehi\EhiMemberKind;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;

/**
 * One package's document evidence, loaded once and indexed in memory.
 *
 * Rows come only from EhiMappingContext::revisions(), so only revisions this
 * package supplied are visible. An entity with more than one revision in the
 * package is ambiguous; it stays indexed so dependants can see it and refuse to
 * guess. Note/media members are the package's own manifest rows.
 */
final class EhiDocumentEvidence
{
    /** @var array<string, list<PhrEhiRevision>> */
    private array $rows = [];

    /** @var array<int, true> revision ids whose entity has another revision in this package */
    private array $ambiguous = [];

    /** @var array<string, array<string, array<string, list<PhrEhiRevision>>>> table => column => value => rows */
    private array $indexes = [];

    /** @var list<PhrEhiPackageMember> */
    private array $members = [];

    /** @var array<string, list<PhrEhiPackageMember>> exact basename => members */
    private array $membersByBasename = [];

    /** @param  list<string>  $tables */
    public static function load(EhiMappingContext $context, array $tables): self
    {
        $evidence = new self;
        foreach ($tables as $table) {
            $revisions = array_values($context->revisions($table)->all());
            $perEntity = [];
            foreach ($revisions as $revision) {
                $perEntity[$revision->entity_id][] = $revision->id;
            }
            foreach ($perEntity as $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) {
                        $evidence->ambiguous[$id] = true;
                    }
                }
            }
            $evidence->rows[$table] = $revisions;
        }

        $evidence->members = array_values(PhrEhiPackageMember::query()
            ->where('package_id', $context->package->id)
            ->where('patient_id', $context->patient->id)
            ->whereIn('kind', [EhiMemberKind::NOTE, EhiMemberKind::MEDIA])
            ->orderBy('path')
            ->orderBy('id')
            ->get()
            ->all());
        foreach ($evidence->members as $member) {
            $evidence->membersByBasename[self::basename($member->path)][] = $member;
        }

        return $evidence;
    }

    /** @return list<PhrEhiRevision> */
    public function rows(string $table): array
    {
        return $this->rows[$table] ?? [];
    }

    /**
     * Rows whose column holds exactly this raw value.
     *
     * @return list<PhrEhiRevision>
     */
    public function where(string $table, string $column, string $value): array
    {
        if (! isset($this->indexes[$table][$column])) {
            $index = [];
            foreach ($this->rows($table) as $revision) {
                $cell = EhiDocumentValues::cell($revision, $column);
                if ($cell !== null) {
                    $index[$cell][] = $revision;
                }
            }
            $this->indexes[$table][$column] = $index;
        }

        return $this->indexes[$table][$column][$value] ?? [];
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

    private static function basename(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
    }
}
