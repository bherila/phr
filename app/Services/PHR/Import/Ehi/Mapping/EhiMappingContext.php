<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrEhiEntity;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiRevision;
use App\Models\PhrEhiSource;
use App\Models\PhrPatient;
use Illuminate\Support\Collection;

/**
 * Read-only view of one bound package's projected evidence for a mapping.
 *
 * Revisions are those this package actually supplied (via its occurrences), so a
 * mapping never proposes from rows another package carried. Quarantined
 * occurrences (a repeated primary key) supply nothing: an ambiguous row never
 * becomes a proposal. Findings a mapping
 * raises (orphan joins, ambiguous keys, ownership failures) are collected here and
 * persisted by the proposal service with the preview.
 */
final class EhiMappingContext
{
    /** @var list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}> */
    private array $findings = [];

    public function __construct(
        public readonly PhrEhiPackage $package,
        public readonly PhrPatient $patient,
        public readonly PhrEhiSource $source,
    ) {}

    /**
     * Revisions of one table supplied by this package, ordered by entity key digest
     * so proposal order is deterministic.
     *
     * @return Collection<int, PhrEhiRevision>
     */
    public function revisions(string $table): Collection
    {
        return PhrEhiRevision::query()
            ->select('phr_ehi_revisions.*')
            ->join('phr_ehi_entities', 'phr_ehi_entities.id', '=', 'phr_ehi_revisions.entity_id')
            ->where('phr_ehi_entities.source_id', $this->source->id)
            ->where('phr_ehi_entities.table_name', $table)
            ->whereIn('phr_ehi_revisions.id', function ($query): void {
                $query->select('revision_id')->from('phr_ehi_occurrences')->where('package_id', $this->package->id)
                    ->where('disposition', '!=', PhrEhiOccurrence::DISPOSITION_QUARANTINED);
            })
            ->orderBy('phr_ehi_entities.key_digest')
            ->orderBy('phr_ehi_revisions.content_digest')
            ->with('entity')
            ->get();
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

    /**
     * Every evidence link already recorded for any revision of this entity.
     *
     * @return Collection<int, PhrEhiRecordLink>
     */
    public function linksForEntity(PhrEhiEntity $entity): Collection
    {
        return PhrEhiRecordLink::query()
            ->whereIn('revision_id', PhrEhiRevision::query()->select('id')->where('entity_id', $entity->id))
            ->orderBy('id')
            ->get();
    }

    /** @param  array<string, int|string|bool|null>  $detail  structural only, never source values */
    public function finding(string $code, ?PhrEhiRevision $revision = null, array $detail = []): void
    {
        $this->findings[] = ['code' => $code, 'revision_id' => $revision?->id, 'detail' => $detail];
    }

    /** @return list<array{code: string, revision_id: int|null, detail: array<string, int|string|bool|null>}> */
    public function findings(): array
    {
        return $this->findings;
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
