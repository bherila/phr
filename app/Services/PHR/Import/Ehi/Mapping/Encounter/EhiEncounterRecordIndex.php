<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Encounter;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrOfficeVisit;
use App\Services\PHR\Import\Ehi\Mapping\EhiCandidateSummary;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiSourceIdBridge;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

/**
 * The patient's visits and this source's encounter evidence links, read once, so
 * reconciling many encounters costs a few streamed reads rather than queries per
 * row. Records this source's own import identity holds are left out of duplicate
 * candidates: a different CSN is by definition a different encounter. Deleted,
 * retracted, and rejected records are never a target; they are kept apart so a
 * person's removal is never silently undone.
 */
final class EhiEncounterRecordIndex
{
    /** @var array<int, array{id: int, digest: string, source: string|null, provider: string|null, date: string|null, live: bool, rule: string|null}> */
    private array $rows = [];

    /** @var array<string, list<int>> Y-m-d => row positions */
    private array $byDay = [];

    /** @var array<string, list<int>> identifier => row positions */
    private array $byIdentifier = [];

    /** @var array<int, list<PhrEhiRecordLink>> */
    private array $links = [];

    /** @var array<string, PhrOfficeVisit> */
    private array $held = [];

    /** @var array<int, PhrOfficeVisit> */
    private array $linked = [];

    /** @var array<string, array<string, mixed>> subject key => attributes of its latest accepted or linked proposal */
    private array $decided = [];

    public function __construct(EhiMappingContext $context, string $ownImportSource, string $encounterTable, string $mapping)
    {
        $scanned = 0;
        $this->loadLinks($context, $encounterTable, $scanned);
        $this->loadDecided($context, $mapping, $scanned);
        foreach ($this->inclusive($context)->where('import_source', $ownImportSource)->lazyById(500) as $record) {
            $context->budget->charge(++$scanned, 0);
            if ($record->external_id !== null) {
                $this->held[$record->external_id] = $record;
            }
        }

        $query = $this->inclusive($context)
            ->where(static fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', $ownImportSource));
        foreach ($query->lazyById(200) as $record) {
            $context->budget->charge(++$scanned, strlen((string) $record->raw_text));
            $this->add($record);
        }
    }

    /** @return list<PhrEhiRecordLink> */
    public function linksOf(int $entityId): array
    {
        return $this->links[$entityId] ?? [];
    }

    public function linkedRecord(int $id): ?PhrOfficeVisit
    {
        return $this->linked[$id] ?? null;
    }

    public function heldIdentity(string $externalId): ?PhrOfficeVisit
    {
        return $this->held[$externalId] ?? null;
    }

    /**
     * What the source said when this subject was last accepted or linked, in any
     * package of this source.
     *
     * @return array<string, mixed>|null
     */
    public function decidedAttributes(string $subject): ?array
    {
        return $this->decided[$subject] ?? null;
    }

    /**
     * Records an earlier import identified by this CSN, each with the rule that held.
     *
     * @return list<array{id: int, digest: string, source: string|null, provider: string|null, date: string|null, live: bool, rule: string|null}>
     */
    public function bridged(string $csn): array
    {
        return array_map(fn (int $position): array => $this->rows[$position], $this->byIdentifier[$csn] ?? []);
    }

    /**
     * Visits on the same calendar date. A date is the only thing two sources reliably
     * share, since names and visit types are spelled differently, and a person decides.
     * A FHIR instant can be a day off the source's local date, so a neighbouring day
     * counts only for a FHIR record with the same provider.
     *
     * @return array{live: EhiCandidateSummary, withdrawn: int}
     */
    public function candidates(string $date, ?string $provider): array
    {
        $positions = array_fill_keys($this->byDay[$date] ?? [], true);
        $folded = self::fold($provider);
        foreach ([self::shift($date, -1), self::shift($date, 1)] as $neighbour) {
            foreach ($this->byDay[$neighbour] ?? [] as $position) {
                if ($folded !== null && $this->rows[$position]['source'] === 'fhir' && $this->rows[$position]['provider'] === $folded) {
                    $positions[$position] = true;
                }
            }
        }

        $live = [];
        $withdrawn = 0;
        foreach (array_keys($positions) as $position) {
            if ($this->rows[$position]['live']) {
                $live[] = $this->rows[$position]['id'];
            } else {
                $withdrawn++;
            }
        }
        sort($live);
        $summary = new EhiCandidateSummary;
        foreach ($live as $id) {
            $summary->add($id);
        }

        return ['live' => $summary, 'withdrawn' => $withdrawn];
    }

    /** @return Builder<PhrOfficeVisit> */
    private function inclusive(EhiMappingContext $context): Builder
    {
        return PhrOfficeVisit::query()->withoutGlobalScope(SoftDeletingScope::class)->where('patient_id', $context->patient->id);
    }

    private function loadLinks(EhiMappingContext $context, string $encounterTable, int &$scanned): void
    {
        $query = PhrEhiRecordLink::query()
            ->join('phr_ehi_revisions', 'phr_ehi_revisions.id', '=', 'phr_ehi_record_links.revision_id')
            ->join('phr_ehi_entities', 'phr_ehi_entities.id', '=', 'phr_ehi_revisions.entity_id')
            ->where('phr_ehi_record_links.patient_id', $context->patient->id)
            ->where('phr_ehi_entities.source_id', $context->source->id)
            ->where('phr_ehi_entities.table_name', $encounterTable)
            ->whereNotNull('phr_ehi_record_links.office_visit_id')
            ->orderBy('phr_ehi_record_links.id')
            ->select(['phr_ehi_record_links.*', 'phr_ehi_revisions.entity_id as ehi_link_entity_id']);
        $recordIds = [];
        foreach ($query->cursor() as $link) {
            $context->budget->charge(++$scanned, 0);
            $this->links[(int) $link->getAttribute('ehi_link_entity_id')][] = $link;
            $recordIds[(int) $link->office_visit_id] = true;
        }
        foreach (array_chunk(array_keys($recordIds), 500) as $chunk) {
            foreach ($this->inclusive($context)->whereIn('id', $chunk)->get() as $record) {
                $this->linked[$record->id] = $record;
            }
        }
    }

    private function loadDecided(EhiMappingContext $context, string $mapping, int &$scanned): void
    {
        $query = PhrEhiProposal::query()
            ->where('patient_id', $context->patient->id)
            ->where('mapping', $mapping)
            ->whereIn('status', [PhrEhiProposal::STATUS_ACCEPTED, PhrEhiProposal::STATUS_LINKED])
            ->whereIn('package_id', PhrEhiPackage::query()->select('id')->where('source_id', $context->source->id))
            ->select(['id', 'subject_key', 'proposed_json']);
        foreach (PhrEhiProposal::inDecisionOrder($query)->cursor() as $proposal) {
            $context->budget->charge(++$scanned, 0);
            $attributes = $proposal->proposed_json['attributes'] ?? null;
            if (is_array($attributes)) {
                $this->decided[$proposal->subject_key] = $attributes;
            }
        }
    }

    private function add(PhrOfficeVisit $record): void
    {
        $date = ($record->visit_date ?? $record->visit_started_at)?->format('Y-m-d');
        $position = count($this->rows);
        $extension = EhiSourceIdBridge::ccdaExtension($record->import_source, $record->external_id);
        $identifiers = EhiSourceIdBridge::fhirEncounterIdentifiers($record->import_source, $record->raw_text);
        $this->rows[] = [
            'id' => $record->id,
            'digest' => EhiTargetDigest::of($record),
            'source' => $record->import_source,
            'provider' => self::fold($record->provider_name),
            'date' => $date,
            'live' => PhrRecordLifecycle::of($record) === PhrRecordLifecycle::ACTIVE && $record->review_status !== PhrReviewStatus::REJECTED,
            'rule' => $extension !== null ? EhiSourceIdBridge::RULE_CCDA_ENCOUNTER_EXTENSION : ($identifiers !== [] ? EhiSourceIdBridge::RULE_FHIR_ENCOUNTER_IDENTIFIER : null),
        ];
        if ($date !== null) {
            $this->byDay[$date][] = $position;
        }
        foreach (array_unique([...($extension === null ? [] : [$extension]), ...$identifiers]) as $identifier) {
            $this->byIdentifier[$identifier][] = $position;
        }
    }

    private static function fold(?string $text): ?string
    {
        $text = $text === null ? '' : mb_strtolower(trim($text));

        return $text === '' ? null : $text;
    }

    private static function shift(string $date, int $days): string
    {
        return Carbon::createFromFormat('!Y-m-d', $date, 'UTC')?->addDays($days)->format('Y-m-d') ?? $date;
    }
}
