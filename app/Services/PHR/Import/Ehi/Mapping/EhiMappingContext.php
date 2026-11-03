<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrEhiEntity;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrEhiSource;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiLimits;
use App\Services\PHR\Import\Ehi\EhiPreviewBudget;
use App\Services\PHR\Import\Ehi\EhiProjectionService;
use App\Services\PHR\ResourceByteSize;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Read-only view of one bound package's projected evidence for a mapping.
 *
 * Revisions are those this package actually supplied (via its occurrences), so a
 * mapping never proposes from rows another package carried. Quarantined
 * occurrences (a repeated primary key) supply nothing: an ambiguous row never
 * becomes a proposal, and a table the package's projection checkpoint records as
 * not projected (a key mismatch) supplies nothing either. Findings a mapping
 * raises (orphan joins, ambiguous keys, ownership failures) are collected here and
 * persisted by the proposal service with the preview.
 */
final class EhiMappingContext
{
    /** Revisions read per page by lazyRevisions(), unless the caller says otherwise. */
    public const int CHUNK = 500;

    /** Alias of the entity key digest selected alongside a page, for the keyset cursor. */
    private const string CURSOR = 'ehi_page_key_digest';

    /** @var list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}> */
    private array $findings = [];

    private int $omittedFindings = 0;

    /** @var array<string, true>|null upper-cased tables this package's projection withheld */
    private ?array $withheldTables = null;

    public function __construct(
        public readonly PhrEhiPackage $package,
        public readonly PhrPatient $patient,
        public readonly PhrEhiSource $source,
        public readonly int $chunk = self::CHUNK,
        public readonly EhiPreviewBudget $budget = new EhiPreviewBudget,
    ) {}

    /**
     * Revisions of one table supplied by this package, ordered by entity key digest
     * so proposal order is deterministic. Loads the whole table: for a table that
     * can be large, stream it with lazyRevisions().
     *
     * @return Collection<int, PhrEhiRevision>
     */
    public function revisions(string $table): Collection
    {
        $rows = [];
        $bytes = 0;
        foreach ($this->lazyRevisions($table, withEntity: true) as $revision) {
            $bytes += strlen((string) $revision->getRawOriginal('values_json'));
            $this->budget->charge(count($rows) + 1, $bytes);
            $rows[] = $revision;
        }

        return new Collection($rows);
    }

    /**
     * The same revisions, in the same order, as revisions(), read a page of
     * $chunk at a time (keyset on entity key digest, then content digest), so at
     * most one page of models is held. Every revision of one entity is adjacent.
     *
     * @return LazyCollection<int, PhrEhiRevision>
     */
    public function lazyRevisions(string $table, ?int $chunk = null, bool $withEntity = false): LazyCollection
    {
        $chunk = max(1, $chunk ?? $this->chunk);

        return LazyCollection::make(function () use ($table, $chunk, $withEntity): Generator {
            if ($this->withheld($table)) {
                return;
            }
            $after = null;
            do {
                $this->budget->assertPatient($this->patient->id);
                $query = PhrEhiRevision::query()
                    ->select('phr_ehi_revisions.*')
                    ->addSelect(['phr_ehi_entities.key_digest as '.self::CURSOR])
                    ->join('phr_ehi_entities', 'phr_ehi_entities.id', '=', 'phr_ehi_revisions.entity_id')
                    ->where('phr_ehi_entities.source_id', $this->source->id)
                    ->where('phr_ehi_entities.table_name', $table)
                    ->whereIn('phr_ehi_revisions.id', function ($query): void {
                        $query->select('revision_id')->from('phr_ehi_occurrences')->where('package_id', $this->package->id)
                            ->where('disposition', '!=', PhrEhiOccurrence::DISPOSITION_QUARANTINED);
                    })
                    ->when($after !== null, static function ($query) use ($after): void {
                        $query->where(static function ($query) use ($after): void {
                            $query->where('phr_ehi_entities.key_digest', '>', $after[0])
                                ->orWhere(static function ($query) use ($after): void {
                                    $query->where('phr_ehi_entities.key_digest', $after[0])
                                        ->where('phr_ehi_revisions.content_digest', '>', $after[1]);
                                });
                        });
                    })
                    ->orderBy('phr_ehi_entities.key_digest')
                    ->orderBy('phr_ehi_revisions.content_digest')
                    ->limit($chunk);
                $sizes = new ResourceByteSize;
                $probes = (clone $query)->select(['phr_ehi_revisions.id'])
                    ->selectRaw('COALESCE('.$sizes->column('phr_ehi_revisions.values_json').', 0) + COALESCE('.$sizes->column('phr_ehi_entities.key_json').', 0) AS raw_bytes')
                    ->toBase()->get();
                $this->budget->charge($probes->count(), (int) $probes->sum('raw_bytes'));
                $page = $probes->isEmpty() ? new \Illuminate\Database\Eloquent\Collection : $query->whereIn('phr_ehi_revisions.id', $probes->pluck('id')->all())->get();
                unset($probes);
                $count = $page->count();
                $last = $page->last();
                $after = $last === null ? null : [(string) $last->getAttribute(self::CURSOR), $last->content_digest];
                foreach ($page as $revision) {
                    // The cursor is a paging detail, not a revision attribute.
                    $revision->setRawAttributes(array_diff_key($revision->getAttributes(), [self::CURSOR => true]), true);
                }
                if ($withEntity) {
                    $page->load('entity');
                }
                foreach ($page as $revision) {
                    yield $revision;
                }
                // Release the page before the next one is read.
                unset($page, $last, $revision);
            } while ($count === $chunk);
        });
    }

    /**
     * The revision this package supplied for one full primary key, or null. More
     * than one revision for the same key in one package is an ambiguity the
     * projection already reported; this returns null for it.
     *
     * @param  array<string, string|null>  $key  column => raw value, every PK column
     */
    public function revisionByKey(string $table, array $key): ?PhrEhiRevision
    {
        if ($this->withheld($table)) {
            return null;
        }

        $matches = PhrEhiRevision::query()
            ->where('entity_id', function ($query) use ($table, $key): void {
                $query->select('id')->from('phr_ehi_entities')
                    ->where('source_id', $this->source->id)
                    ->where('table_name', $table)
                    ->where('key_digest', self::keyDigest($key));
            })
            ->whereIn('id', function ($query): void {
                $query->select('revision_id')->from('phr_ehi_occurrences')->where('package_id', $this->package->id)
                    ->where('disposition', '!=', PhrEhiOccurrence::DISPOSITION_QUARANTINED);
            })
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Whether this package's projection left the table unprojected, so it supplies no evidence. */
    public function withheld(string $table): bool
    {
        $this->withheldTables ??= EhiProjectionService::withheldTables($this->package);

        return isset($this->withheldTables[strtoupper($table)]);
    }

    /**
     * Where this package's rows of a table do not add up to a complete set, so a row's
     * absence means nothing: every key, when the table supplied nothing or held a row
     * that could not be read or had no usable key, or only the keys whose rows were
     * quarantined. Quarantined rows are never revisions a mapping sees.
     *
     * @return array{all: bool, keys: array<string, true>}
     */
    public function incompleteRows(string $table, string $keyColumn): array
    {
        $everything = ['all' => true, 'keys' => []];
        if ($this->withheld($table)) {
            return $everything;
        }
        $account = PhrEhiTableAccount::query()->where('package_id', $this->package->id)->whereNotNull('member_id')->get()
            ->first(static fn (PhrEhiTableAccount $row): bool => strtoupper($row->table_name) === strtoupper($table));
        if ($account === null || $account->malformed_row_count > 0) {
            return $everything;
        }
        $quarantined = (int) ($account->disposition_counts_json[PhrEhiOccurrence::DISPOSITION_QUARANTINED] ?? 0);
        if ($quarantined === 0) {
            return ['all' => false, 'keys' => []];
        }
        $rows = PhrEhiRevision::query()
            ->join('phr_ehi_occurrences', 'phr_ehi_occurrences.revision_id', '=', 'phr_ehi_revisions.id')
            ->where('phr_ehi_occurrences.member_id', $account->member_id)
            ->where('phr_ehi_occurrences.disposition', PhrEhiOccurrence::DISPOSITION_QUARANTINED)
            ->select('phr_ehi_revisions.*', 'phr_ehi_occurrences.id as ehi_occurrence_id')
            ->get();
        if ($rows->count() < $quarantined) {
            return $everything;
        }
        $keys = [];
        foreach ($rows as $row) {
            $value = $row->values_json[$keyColumn] ?? null;
            if (is_string($value) && $value !== '') {
                $keys[$value] = true;
            }
        }

        return ['all' => false, 'keys' => $keys];
    }

    /**
     * Every evidence link already recorded for any revision of this entity.
     *
     * @return Collection<int, PhrEhiRecordLink>
     */
    public function linksForEntity(PhrEhiEntity $entity): Collection
    {
        $this->budget->assertPatient($this->patient->id);

        return PhrEhiRecordLink::query()
            ->whereIn('revision_id', PhrEhiRevision::query()->select('id')->where('entity_id', $entity->id))
            ->orderBy('id')
            ->limit((int) config('phr.ehi_preview_max_rows') + 1)
            ->get()->tap(fn ($rows) => $this->budget->charge($rows->count(), 0));
    }

    /**
     * Every evidence link of this patient with one target column set, by the entity its
     * revision belongs to, oldest first. One read, for a mapping to reconcile many rows.
     *
     * @return array<int, list<PhrEhiRecordLink>>
     */
    public function linksByEntity(string $targetColumn): array
    {
        $this->budget->assertPatient($this->patient->id);
        $query = PhrEhiRecordLink::query()
            ->join('phr_ehi_revisions', 'phr_ehi_revisions.id', '=', 'phr_ehi_record_links.revision_id')
            ->where('phr_ehi_record_links.patient_id', $this->patient->id)
            ->whereNotNull('phr_ehi_record_links.'.$targetColumn)
            ->orderBy('phr_ehi_record_links.id')
            ->select(['phr_ehi_record_links.*', 'phr_ehi_revisions.entity_id as ehi_link_entity_id']);
        $links = [];
        $scanned = 0;
        foreach ($query->cursor() as $link) {
            $this->budget->charge(++$scanned, 0);
            $links[(int) $link->getAttribute('ehi_link_entity_id')][] = $link;
        }

        return $links;
    }

    /** @param  array<string, int|string|bool|null>  $detail  structural only, never source values */
    public function finding(string $code, ?PhrEhiRevision $revision = null, array $detail = []): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
            throw new \LogicException('EHI mapping raised a finding without a fixed code.');
        }
        if (count($this->findings) >= EhiLimits::fromConfig()->maxFindingsPerMember) {
            $this->omittedFindings++;

            return;
        }
        $this->findings[] = ['code' => $code, 'revision_id' => $revision?->id, 'detail' => $detail];
    }

    /** @return list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}> */
    public function findings(): array
    {
        return $this->findings;
    }

    public function omittedFindings(): int
    {
        return $this->omittedFindings;
    }

    /**
     * Canonical digest of a full primary key: columns sorted by name, raw values
     * kept exactly (strings, never numbers), so "007" and "7" stay distinct.
     *
     * @param  array<string, string|null>  $key
     */
    public static function keyDigest(array $key): string
    {
        ksort($key, SORT_STRING);

        return hash('sha256', json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
