<?php

namespace App\Services\PHR\Import\Ehi\Mapping\Lab;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrLabResult;
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
 * The patient's lab results and this source's lab evidence links, read once, so
 * reconciling thousands of rows costs a few streamed reads rather than queries per
 * row. Earlier imports are held compactly; records this source or a link holds are
 * models, because reconciling compares every mapped field.
 *
 * Records this source's own import identity holds are left out of duplicate
 * candidates: an entity with a
 * different full key is by definition a different result, which is what keeps
 * same-day repeated tests from flagging each other, whatever order they are
 * accepted in. Deleted, retracted, and rejected records are kept apart from live
 * ones: they are never a target, but their existence is what stops a person's
 * removal from being silently undone.
 */
final class EhiLabRecordIndex
{
    /** @var array<int, array{id: int, digest: string, source: string|null, analyte: string|null, value: string|null, number: string|null, comparator: string|null, unit: string|null, dates: list<string>, live: bool}> */
    private array $rows = [];

    /** @var array<string, array<string, list<int>>> date => folded analyte => row positions */
    private array $byDay = [];

    /** @var array<string, list<int>> C-CDA id extension => row positions */
    private array $byExtension = [];

    /** @var array<int, list<PhrEhiRecordLink>> entity id => its lab evidence links */
    private array $links = [];

    /** @var array<string, PhrLabResult> this source's records by exact external id */
    private array $held = [];

    /** @var array<int, PhrLabResult> records a link names, this source's or an earlier import's */
    private array $linked = [];

    /** @var array<string, array<string, mixed>> subject key => attributes of its latest accepted or linked proposal */
    private array $decided = [];

    public function __construct(EhiMappingContext $context, string $ownImportSource, string $resultTable, string $mapping)
    {
        $scanned = 0;
        $this->loadLinks($context, $resultTable, $scanned);
        $this->loadDecided($context, $mapping, $scanned);
        foreach ($this->inclusive($context)->where('import_source', $ownImportSource)->lazyById(500) as $record) {
            $context->budget->charge(++$scanned, 0);
            if ($record->external_id !== null) {
                $this->held[$record->external_id] = $record;
            }
        }

        $query = $this->inclusive($context)
            ->where(static fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', $ownImportSource));
        foreach ($query->lazyById(500) as $record) {
            $context->budget->charge(++$scanned, 0);
            $this->add($record);
        }
    }

    /**
     * Every lab evidence link recorded for an entity, oldest first.
     *
     * @return list<PhrEhiRecordLink>
     */
    public function linksOf(int $entityId): array
    {
        return $this->links[$entityId] ?? [];
    }

    /** A record a link names, withdrawn or not; null if it no longer exists. */
    public function linkedRecord(int $id): ?PhrLabResult
    {
        return $this->linked[$id] ?? null;
    }

    /** The record holding this source's import identity, withdrawn or not. */
    public function heldIdentity(string $externalId): ?PhrLabResult
    {
        return $this->held[$externalId] ?? null;
    }

    /**
     * What the source said when this subject was last accepted or linked, in any
     * package of this source, without the fields that package could not vouch for.
     *
     * @return array<string, mixed>|null
     */
    public function decidedAttributes(string $subject): ?array
    {
        return $this->decided[$subject] ?? null;
    }

    /**
     * Records whose C-CDA id extension equals this order id and whose analyte matches.
     *
     * @param  list<string>  $analytes  folded names the result goes by
     * @return list<array{id: int, digest: string, source: string|null, analyte: string|null, value: string|null, number: string|null, comparator: string|null, unit: string|null, dates: list<string>, live: bool}>
     */
    public function bridged(string $orderProcId, array $analytes): array
    {
        $rows = [];
        foreach ($this->byExtension[$orderProcId] ?? [] as $position) {
            if (in_array($this->rows[$position]['analyte'], $analytes, true)) {
                $rows[] = $this->rows[$position];
            }
        }

        return $rows;
    }

    /**
     * Records that are probably the same result: same analyte, same calendar date
     * on either date column, and the same value (a value missing on either side
     * cannot rule one out). A different value the same day stays a repeated test.
     * An import from FHIR stores the instant its source wrote, usually UTC, so its
     * calendar date can be a day off the source's local date; its neighbours count.
     *
     * @param  list<string>  $analytes  folded names the result goes by
     * @param  list<string>  $dates  Y-m-d
     * @return array{live: EhiCandidateSummary, withdrawn: int}
     */
    public function candidates(array $analytes, array $dates, ?string $value, ?string $comparator, ?string $number): array
    {
        $positions = [];
        foreach ($dates as $date) {
            foreach ($analytes as $analyte) {
                foreach ($this->byDay[$date][$analyte] ?? [] as $position) {
                    $positions[$position] = true;
                }
                foreach ([self::shift($date, -1), self::shift($date, 1)] as $neighbour) {
                    foreach ($this->byDay[$neighbour][$analyte] ?? [] as $position) {
                        if ($this->rows[$position]['source'] === 'fhir') {
                            $positions[$position] = true;
                        }
                    }
                }
            }
        }

        $live = [];
        $withdrawn = 0;
        foreach (array_keys($positions) as $position) {
            $row = $this->rows[$position];
            if (! $this->sameValue($row, $value, $comparator, $number)) {
                continue;
            }
            if ($row['live']) {
                $live[] = $row['id'];
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

    public static function fold(?string $text): ?string
    {
        $text = $text === null ? '' : mb_strtolower(trim($text));

        return $text === '' ? null : $text;
    }

    /** @return Builder<PhrLabResult> */
    private function inclusive(EhiMappingContext $context): Builder
    {
        return PhrLabResult::query()->withoutGlobalScope(SoftDeletingScope::class)->where('patient_id', $context->patient->id);
    }

    private function loadLinks(EhiMappingContext $context, string $resultTable, int &$scanned): void
    {
        $query = PhrEhiRecordLink::query()
            ->join('phr_ehi_revisions', 'phr_ehi_revisions.id', '=', 'phr_ehi_record_links.revision_id')
            ->join('phr_ehi_entities', 'phr_ehi_entities.id', '=', 'phr_ehi_revisions.entity_id')
            ->where('phr_ehi_record_links.patient_id', $context->patient->id)
            ->where('phr_ehi_entities.source_id', $context->source->id)
            ->where('phr_ehi_entities.table_name', $resultTable)
            ->whereNotNull('phr_ehi_record_links.lab_result_id')
            ->orderBy('phr_ehi_record_links.id')
            ->select(['phr_ehi_record_links.*', 'phr_ehi_revisions.entity_id as ehi_link_entity_id']);
        $recordIds = [];
        foreach ($query->cursor() as $link) {
            $context->budget->charge(++$scanned, 0);
            $this->links[(int) $link->getAttribute('ehi_link_entity_id')][] = $link;
            $recordIds[(int) $link->lab_result_id] = true;
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
            ->orderBy('decided_at')
            ->orderBy('id')
            ->select(['id', 'subject_key', 'proposed_json']);
        foreach ($query->cursor() as $proposal) {
            $context->budget->charge(++$scanned, 0);
            $attributes = $proposal->proposed_json['attributes'] ?? null;
            if (is_array($attributes)) {
                $unavailable = $proposal->proposed_json['ehi']['unavailable_fields'] ?? [];
                $this->decided[$proposal->subject_key] = array_diff_key($attributes, array_flip(is_array($unavailable) ? array_filter($unavailable, 'is_string') : []));
            }
        }
    }

    private function add(PhrLabResult $record): void
    {
        $dates = array_values(array_unique(array_filter([
            $record->collection_datetime?->format('Y-m-d'),
            $record->result_datetime?->format('Y-m-d'),
        ])));
        $analyte = self::fold($record->analyte);
        $position = count($this->rows);
        $this->rows[] = [
            'id' => $record->id,
            'digest' => EhiTargetDigest::of($record),
            'source' => $record->import_source,
            'analyte' => $analyte,
            'value' => self::fold($record->value),
            'number' => $record->value_numeric === null ? null : EhiLabValue::canonical($record->value_numeric),
            'comparator' => $record->value_comparator,
            'unit' => self::fold($record->unit),
            'dates' => $dates,
            'live' => PhrRecordLifecycle::of($record) === PhrRecordLifecycle::ACTIVE && $record->review_status !== PhrReviewStatus::REJECTED,
        ];
        if ($analyte !== null) {
            foreach ($dates as $date) {
                $this->byDay[$date][$analyte][] = $position;
            }
        }
        $extension = EhiSourceIdBridge::ccdaExtension($record->import_source, $record->external_id);
        if ($extension !== null) {
            $this->byExtension[$extension][] = $position;
        }
    }

    /** @param  array{value: string|null, number: string|null, comparator: string|null, unit: string|null}  $row */
    private function sameValue(array $row, ?string $value, ?string $comparator, ?string $number): bool
    {
        if ($value === null || ($row['value'] === null && $row['number'] === null)) {
            return true;
        }
        $canonicalNumber = EhiLabValue::canonical($number);
        if ($canonicalNumber !== null && $row['number'] !== null) {
            return $canonicalNumber === $row['number'];
        }
        $folded = self::fold($value);
        if ($row['value'] === $folded) {
            return true;
        }

        // A FHIR value is stored as "value unit"; one side may write an inequality in
        // its value that the other states beside it.
        return ($row['unit'] !== null && $row['value'] === $folded.' '.$row['unit'])
            || EhiLabValue::agree($value, $comparator, null, $row['value'], $row['comparator'], null);
    }

    private static function shift(string $date, int $days): string
    {
        return Carbon::createFromFormat('!Y-m-d', $date, 'UTC')?->addDays($days)->format('Y-m-d') ?? $date;
    }
}
