<?php

namespace App\Services\PHR\Encounters;

use App\Models\PhrOfficeVisit;
use App\Support\PHR\PhrMedicationCategory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The records a visit or stay probably holds, from its dates (#450): standing records of the
 * patient dated inside the stay that no visit holds yet, for a person (or an agent on the
 * person's word) to confirm. Nothing here links anything: confirming goes through
 * PhrEncounterLinkBatch, one PhrEncounterLinks::link per record.
 *
 * The window is a range of calendar days, compared as stored, never converted between zones:
 * a record's natural date (PhrLinkableRecords::FIELDS, the first of its date columns that is
 * set) matches when its day falls on one of the window's days, so a day-granular date (a
 * procedure's performed date, a document's date) and a moment compare alike. That is how the
 * visit page and the attach dialog read a date, so a suggestion falls on the day the page
 * shows. A moment stored in UTC near midnight may belong to the neighbouring local day; the
 * margin covers it.
 *
 * - It starts on the day the stay started (`visit_started_at`, else `visit_date`).
 * - It ends on the day the stay ended (`visit_ended_at`). A stay under way with no end
 *   (encounter_state arrived, triaged, in-progress or on leave) runs to today, read on the
 *   latest clock on earth (UTC+14) so a time a person wrote on their own clock is never past
 *   it. Any other visit without an end is its one day, so an office visit that recorded only
 *   its start never reaches to the present.
 * - Each side is widened by `margin_days` (0 to 14), except an open end: nothing after today.
 *
 * Within the window, per type: lab results by collection (else result) time, vitals by
 * observed time (else date), procedures by performance, documents by authoring time (else
 * document date), and medications by start, only an inpatient or discharge order: a home
 * (community) or uncategorised medication is the stay's transitions' business (#414), and a
 * clinic's is not the stay's. A record another standing visit holds is listed apart, under
 * on_other_visits, since it must be removed there before it can move. Rejected, retracted and
 * deleted records, and those already on this visit, are left out. Each list runs by date,
 * then by record, holds at most `limit` records with its total count, and pages on its own
 * keyset cursor.
 *
 * @phpstan-import-type LinkableRecord from PhrLinkableRecords
 *
 * @phpstan-type SuggestionList array{records: list<LinkableRecord>, total: int, next_cursor: string|null}
 * @phpstan-type SuggestionWindow array{from: string, to: string, open: bool, margin_days: int}
 */
final class PhrLinkSuggestions
{
    public const int MAX_MARGIN_DAYS = 14;

    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 50;

    /** Records no visit holds: what the person is asked to confirm. */
    public const string SUGGESTED = 'suggested';

    /** Records dated inside the stay that another standing visit holds. */
    public const string ON_OTHER_VISITS = 'on_other_visits';

    public const array LISTS = [self::SUGGESTED, self::ON_OTHER_VISITS];

    /** The medication settings a stay's own orders have. */
    public const array MEDICATION_CATEGORIES = [PhrMedicationCategory::INPATIENT, PhrMedicationCategory::DISCHARGE];

    /** FHIR Encounter.status values of an encounter still under way. */
    public const array UNDER_WAY = ['arrived', 'triaged', 'in-progress', 'onleave'];

    /** The latest UTC offset in use, in hours: the clock on which today ends last. */
    private const int LATEST_OFFSET_HOURS = 14;

    public function __construct(private PhrLinkableRecords $records) {}

    /**
     * The stay's window of days, or null for a visit with no date at all.
     *
     * @return SuggestionWindow|null
     */
    public static function window(PhrOfficeVisit $visit, int $marginDays, CarbonInterface $now): ?array
    {
        $start = $visit->visit_started_at ?? $visit->visit_date;
        if ($start === null) {
            return null;
        }
        $from = CarbonImmutable::parse($start->toDateString());
        $open = $visit->visit_ended_at === null && in_array($visit->encounter_state, self::UNDER_WAY, true);
        if ($visit->visit_ended_at !== null) {
            $to = CarbonImmutable::parse($visit->visit_ended_at->toDateString())->addDays($marginDays);
        } elseif ($open) {
            $to = CarbonImmutable::parse(CarbonImmutable::instance($now)->utc()->addHours(self::LATEST_OFFSET_HOURS)->toDateString());
        } else {
            $to = $from->addDays($marginDays);
        }
        $from = $from->subDays($marginDays);

        return [
            'from' => $from->toDateString(),
            'to' => ($to->lessThan($from) ? $from : $to)->toDateString(),
            'open' => $open,
            'margin_days' => $marginDays,
        ];
    }

    /**
     * @param  list<string>  $types  link types, in the order to list them
     * @param  string|null  $list  one of LISTS to read only that list, as a cursor needs
     * @return array{window: SuggestionWindow|null, groups: array<string, array{suggested?: SuggestionList, on_other_visits?: SuggestionList}>}
     */
    public function suggest(int $patientId, PhrOfficeVisit $visit, array $types, int $marginDays, int $limit, ?string $list = null, ?string $cursor = null, ?CarbonInterface $now = null): array
    {
        $window = self::window($visit, $marginDays, $now ?? CarbonImmutable::now());
        $after = PhrLinkableRecords::decodeCursor($cursor);
        $groups = [];
        foreach ($types as $type) {
            foreach (self::LISTS as $name) {
                if ($list !== null && $list !== $name) {
                    continue;
                }
                $groups[$type][$name] = $window === null
                    ? ['records' => [], 'total' => 0, 'next_cursor' => null]
                    : $this->list($patientId, (int) $visit->id, $type, $name, $window, $limit, $after);
            }
        }

        return ['window' => $window, 'groups' => $groups];
    }

    /**
     * @param  SuggestionWindow  $window
     * @param  array{at: string, id: int}|null  $after
     * @return SuggestionList
     */
    private function list(int $patientId, int $visitId, string $type, string $list, array $window, int $limit, ?array $after): array
    {
        $qualify = PhrLinkableRecords::qualifier($type);
        $natural = PhrLinkableRecords::naturalDateSql($type);
        $definition = PhrEncounterLinks::definition($type);
        $heldElsewhere = static fn (QueryBuilder $links): QueryBuilder => $links->select('links.'.$definition['column'])
            ->from($definition['table'].' as links')
            ->join('phr_office_visits as visits', 'visits.id', '=', 'links.office_visit_id')
            ->where('links.patient_id', $patientId)
            ->where('links.office_visit_id', '!=', $visitId)
            ->whereNull('visits.deleted_at')
            ->whereNull('visits.retracted_at');

        $query = PhrLinkableRecords::candidates($patientId, $visitId, $type)
            ->selectRaw("{$natural} as link_sort_at")
            // Days as strings: a date sorts before any time on it, and the day after ends the window.
            ->whereRaw("{$natural} >= ?", [$window['from']])
            ->whereRaw("{$natural} < ?", [CarbonImmutable::parse($window['to'])->addDay()->toDateString()]);
        if ($type === 'medication') {
            $query->whereIn($qualify('category'), self::MEDICATION_CATEGORIES);
        }
        $list === self::SUGGESTED
            ? $query->whereNotIn($qualify('id'), $heldElsewhere)
            : $query->whereIn($qualify('id'), $heldElsewhere);

        $total = (clone $query)->toBase()->getCountForPagination();
        if ($after !== null) {
            $query->where(function (Builder $later) use ($natural, $qualify, $after): void {
                $later->whereRaw("{$natural} > ?", [$after['at']])
                    ->orWhereRaw("{$natural} = ? AND ".$qualify('id').' > ?', [$after['at'], $after['id']]);
            });
        }
        $rows = $query->orderByRaw("{$natural} ASC")->orderBy($qualify('id'))->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        /** @var Model|null $last */
        $last = $rows->last();

        return [
            'records' => $this->records->rows($patientId, $type, $rows),
            'total' => $total,
            'next_cursor' => $hasMore && $last !== null
                ? PhrLinkableRecords::encodeCursor((string) $last->getAttribute('link_sort_at'), (int) $last->getKey())
                : null,
        ];
    }
}
