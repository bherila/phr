<?php

namespace App\Services\PHR\Encounters;

use App\Models\PhrOfficeVisit;
use App\Support\PHR\PhrReviewStatus;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * The patient's records of one link type that a visit could take (#449): standing (not
 * retracted, deleted or rejected) and not on this visit already. A record on another
 * standing visit is listed with that visit, since it must be unlinked there first.
 *
 * Each type has a natural date, the first of its date columns that is set: a lab's
 * collection (else result) time, a vital's observed time (else its date), a medication's
 * start, a procedure's performance, a document's authoring time (else its document date).
 * `from`/`to` filter on it, so an undated record falls outside any range. The list runs
 * newest first by that date, then by when the record was written for an undated one, and
 * pages on a keyset cursor.
 *
 * @phpstan-type LinkableRecord array{id: int, type: string, name: string, date: string|null, detail: string|null, review_status: string, linked_visit: array{id: int, date: string|null, visit_type: string|null}|null}
 */
final class PhrLinkableRecords
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 100;

    /**
     * Link type => the columns its name is read from (and `q` matches), its date columns in
     * order, and the two columns its detail line is made of. Never a request's choice.
     *
     * @var array<string, array{names: non-empty-list<string>, dates: non-empty-list<string>, detail: list<string>}>
     */
    public const array FIELDS = [
        'lab-result' => ['names' => ['analyte', 'test_name'], 'dates' => ['collection_datetime', 'result_datetime'], 'detail' => ['value', 'unit']],
        'vital' => ['names' => ['vital_name'], 'dates' => ['observed_at', 'vital_date'], 'detail' => ['vital_value', 'unit']],
        'medication' => ['names' => ['name', 'brand_name'], 'dates' => ['started_at', 'started_on'], 'detail' => ['dose', 'dose_unit']],
        'procedure' => ['names' => ['name'], 'dates' => ['performed_at', 'performed_on'], 'detail' => ['status']],
        'document' => ['names' => ['title', 'original_filename'], 'dates' => ['authored_at', 'observed_at'], 'detail' => ['document_type']],
    ];

    public function __construct(private PhrEncounterLinks $links) {}

    /**
     * @param  array{q?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{records: list<LinkableRecord>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    public function page(int $patientId, int $visitId, string $type, array $filters, int $limit, ?string $cursor): array
    {
        $fields = self::FIELDS[$type];
        $qualify = self::qualifier($type);
        $natural = self::naturalDateSql($type);
        $sort = self::coalesce(array_map($qualify, [...$fields['dates'], 'created_at']));
        $after = self::decodeCursor($cursor);

        $query = self::candidates($patientId, $visitId, $type)->selectRaw("{$sort} as link_sort_at");

        $text = trim((string) ($filters['q'] ?? ''));
        if ($text !== '') {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%';
            $query->where(function (Builder $matches) use ($fields, $qualify, $needle): void {
                foreach ($fields['names'] as $column) {
                    $matches->orWhereRaw('LOWER('.$qualify($column).") LIKE ? ESCAPE '!'", [$needle]);
                }
            });
        }
        if (($filters['from'] ?? null) !== null) {
            $query->whereRaw("{$natural} >= ?", [Carbon::parse((string) $filters['from'])->toDateString()]);
        }
        if (($filters['to'] ?? null) !== null) {
            $query->whereRaw("{$natural} <= ?", [Carbon::parse((string) $filters['to'])->endOfDay()->toDateTimeString()]);
        }
        if ($after !== null) {
            $query->where(function (Builder $later) use ($sort, $qualify, $after): void {
                $later->whereRaw("{$sort} < ?", [$after['at']])
                    ->orWhereRaw("{$sort} = ? AND ".$qualify('id').' < ?', [$after['at'], $after['id']]);
            });
        }

        $rows = $query->orderByRaw("{$sort} DESC")->orderByDesc($qualify('id'))->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $last = $rows->last();

        return [
            'records' => $this->rows($patientId, $type, $rows),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last !== null
                    ? self::encodeCursor((string) $last->getAttribute('link_sort_at'), (int) $last->getKey())
                    : null,
            ],
        ];
    }

    /**
     * The patient's standing records of one type that this visit does not hold yet (not
     * retracted, deleted or rejected), selecting the columns a listed row reads. The stay's
     * suggestions (#450) start from it too, so both lists leave out the same records.
     *
     * @return Builder<Model>
     */
    public static function candidates(int $patientId, int $visitId, string $type): Builder
    {
        $definition = PhrEncounterLinks::definition($type);
        $fields = self::FIELDS[$type];
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $qualify = self::qualifier($type);

        /** @var Builder<Model> $query */
        $query = $modelClass::query()
            ->select(array_map($qualify, array_values(array_unique(['id', 'review_status', ...$fields['names'], ...$fields['dates'], ...$fields['detail']]))))
            ->where($qualify('patient_id'), $patientId)
            ->whereNull($qualify('retracted_at'))
            ->where($qualify('review_status'), '!=', PhrReviewStatus::REJECTED)
            ->whereNotIn($qualify('id'), fn (QueryBuilder $links) => $links->select($definition['column'])->from($definition['table'])
                ->where('patient_id', $patientId)->where('office_visit_id', $visitId));

        return $query;
    }

    /** The SQL of a type's natural date: the first of its date columns that is set. */
    public static function naturalDateSql(string $type): string
    {
        return self::coalesce(array_map(self::qualifier($type), self::FIELDS[$type]['dates']));
    }

    /** @return Closure(string): string a column of the type's table, qualified */
    public static function qualifier(string $type): Closure
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = PhrEncounterLinks::definition($type)['model'];
        $model = new $modelClass;

        return static fn (string $column): string => $model->qualifyColumn($column);
    }

    /**
     * Each record as a listed row, naming the other standing visit it is on, if any.
     *
     * @param  Collection<int, Model>  $rows
     * @return list<LinkableRecord>
     */
    public function rows(int $patientId, string $type, Collection $rows): array
    {
        $fields = self::FIELDS[$type];
        $elsewhere = $this->links->encounterIds($patientId, $type, $rows->modelKeys());
        $visits = self::visitSummaries($patientId, array_values(array_unique($elsewhere)));

        return $rows->map(static fn (Model $record): array => [
            'id' => (int) $record->getKey(),
            'type' => $type,
            'name' => self::name($record, $fields['names']),
            'date' => self::naturalDate($record, $fields['dates']),
            'detail' => self::detail($record, $fields['detail']),
            'review_status' => (string) $record->getAttribute('review_status'),
            'linked_visit' => isset($elsewhere[(int) $record->getKey()]) ? ($visits[$elsewhere[(int) $record->getKey()]] ?? null) : null,
        ])->values()->all();
    }

    /**
     * Each standing visit's id, date and type, as a refusal or a listed row names it.
     *
     * @param  list<int>  $visitIds
     * @return array<int, array{id: int, date: string|null, visit_type: string|null}>
     */
    public static function visitSummaries(int $patientId, array $visitIds): array
    {
        if ($visitIds === []) {
            return [];
        }

        return PhrOfficeVisit::query()
            ->where('patient_id', $patientId)
            ->whereNull('retracted_at')
            ->whereIn('id', $visitIds)
            ->get(['id', 'visit_date', 'visit_started_at', 'visit_type'])
            ->mapWithKeys(static fn (PhrOfficeVisit $visit): array => [(int) $visit->id => [
                'id' => (int) $visit->id,
                'date' => ($visit->visit_date ?? $visit->visit_started_at)?->toDateString(),
                'visit_type' => $visit->visit_type,
            ]])
            ->all();
    }

    /** @param  non-empty-list<string>  $columns */
    private static function coalesce(array $columns): string
    {
        return count($columns) === 1 ? $columns[0] : 'COALESCE('.implode(', ', $columns).')';
    }

    /** @param  non-empty-list<string>  $columns */
    private static function name(Model $record, array $columns): string
    {
        foreach ($columns as $column) {
            $value = trim((string) $record->getAttribute($column));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Record '.$record->getKey();
    }

    /** @param  non-empty-list<string>  $columns */
    private static function naturalDate(Model $record, array $columns): ?string
    {
        foreach ($columns as $column) {
            $value = $record->getAttribute($column);
            if ($value instanceof CarbonInterface) {
                // A date column is a day; a datetime column is a moment.
                return $record->hasCast($column, ['date', 'immutable_date']) ? $value->toDateString() : $value->toDateTimeString();
            }
        }

        return null;
    }

    /** @param  list<string>  $columns */
    private static function detail(Model $record, array $columns): ?string
    {
        $parts = array_values(array_filter(
            array_map(static fn (string $column): string => trim((string) $record->getAttribute($column)), $columns),
            static fn (string $part): bool => $part !== '',
        ));

        return $parts === [] ? null : implode(' ', $parts);
    }

    public static function encodeCursor(string $at, int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['at' => $at, 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{at: string, id: int}|null */
    public static function decodeCursor(?string $encoded): ?array
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        try {
            $value = is_string($decoded) ? json_decode($decoded, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $value = null;
        }
        if (
            ! is_array($value)
            || array_keys($value) !== ['at', 'id']
            || ! is_string($value['at'])
            || preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)?$/D', $value['at']) !== 1
            || ! is_int($value['id'])
            || $value['id'] < 1
        ) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return $value;
    }
}
