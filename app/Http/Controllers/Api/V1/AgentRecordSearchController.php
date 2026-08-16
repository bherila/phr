<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiUpdateWindow;
use App\Support\AgentApi\AgentRecordCursor;
use App\Support\AgentApi\AgentRecordSearchCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * @phpstan-type SearchDefinition array{
 *   model: class-string<Model>, event: non-empty-list<string>, summary: non-empty-list<string>,
 *   q: list<string>, provider: list<string>, facility: list<string>, codes: list<string>,
 *   sources: list<string>, review: list<string>, source_document: bool
 * }
 * @phpstan-type RecordCursor array{event_at: string, resource_type: string, id: int}
 * @phpstan-type ProjectedRecord array{cursor: RecordCursor, payload: array<string, mixed>}
 */
final class AgentRecordSearchController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function search(Request $request, int $patient): JsonResponse
    {
        return $this->respond($request, $patient, 'search');
    }

    public function timeline(Request $request, int $patient): JsonResponse
    {
        return $this->respond($request, $patient, 'timeline');
    }

    private function respond(Request $request, int $patient, string $view): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $resolvedPatient = $this->accessService->accessiblePatientWithCurrentGrant(
            $patient,
            (int) $request->user('api')?->id,
        );
        $limit = (int) ($validated['limit'] ?? 25);
        $cursor = AgentRecordCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null);
        $types = $validated['resource_type'] ?? AgentRecordSearchCatalog::ids();
        $records = collect();

        foreach ($types as $type) {
            $definition = AgentRecordSearchCatalog::definition((string) $type);
            $records = $records->concat($this->recordsForType(
                (string) $type,
                $definition,
                (int) $resolvedPatient->id,
                $validated,
                $cursor,
                $limit + 1,
            ));
        }

        $ordered = $records
            ->sort(fn (array $left, array $right): int => $this->compare($left, $right))
            ->values();
        $hasMore = $ordered->count() > $limit;
        $page = $ordered->take($limit)->values();
        $last = $page->last();

        return response()->json([
            'view' => $view,
            'patient_id' => $resolvedPatient->id,
            'data' => $page->map(fn (array $record): array => $record['payload'])->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && is_array($last)
                    ? AgentRecordCursor::encode($last['cursor'])
                    : null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'resource_type' => ['sometimes', 'array', 'min:1', 'max:9'],
            'resource_type.*' => ['string', 'distinct', Rule::in(AgentRecordSearchCatalog::ids())],
            'q' => ['sometimes', 'string', 'max:200'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'provider' => ['sometimes', 'string', 'max:200'],
            'facility' => ['sometimes', 'string', 'max:200'],
            'code' => ['sometimes', 'string', 'max:100'],
            'source' => ['sometimes', 'string', 'max:100'],
            'review_status' => ['sometimes', 'string', 'max:50'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
        ];
    }

    /**
     * @param  SearchDefinition  $definition
     * @param  array<string, mixed>  $validated
     * @param  RecordCursor|null  $cursor
     * @return Collection<int, ProjectedRecord>
     */
    private function recordsForType(
        string $type,
        array $definition,
        int $patientId,
        array $validated,
        ?array $cursor,
        int $limit,
    ): Collection {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $model = new $modelClass;
        $eventColumns = array_map(
            fn (string $column): string => $model->qualifyColumn($column),
            $definition['event'],
        );
        $eventExpression = count($eventColumns) === 1
            ? $eventColumns[0]
            : 'COALESCE('.implode(', ', $eventColumns).')';
        $query = $modelClass::query()
            ->where($model->qualifyColumn('patient_id'), $patientId)
            ->addSelect($model->qualifyColumn('*'))
            ->selectRaw("{$eventExpression} as agent_event_at");

        AgentApiUpdateWindow::apply($query, $validated, $model->qualifyColumn('patient_id'));
        $this->applyFilters($query, $definition, $validated, $eventExpression);
        $this->applyCursor($query, $type, $cursor, $eventExpression, $model->qualifyColumn('id'));

        $records = [];
        foreach ($query
            ->orderByRaw("{$eventExpression} DESC")
            ->orderByDesc($model->qualifyColumn('id'))
            ->limit($limit)
            ->get() as $record) {
            $records[] = $this->project($type, $definition, $record);
        }

        return collect($records);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  SearchDefinition  $definition
     * @param  array<string, mixed>  $validated
     */
    private function applyFilters(Builder $query, array $definition, array $validated, string $eventExpression): void
    {
        if (isset($validated['date_from'])) {
            $query->whereRaw("{$eventExpression} >= ?", [Carbon::parse((string) $validated['date_from'])->toDateString()]);
        }
        if (isset($validated['date_to'])) {
            $query->whereRaw("{$eventExpression} <= ?", [Carbon::parse((string) $validated['date_to'])->endOfDay()->toDateTimeString()]);
        }
        foreach (['q', 'provider', 'facility', 'code', 'source', 'review_status'] as $filter) {
            if (! isset($validated[$filter])) {
                continue;
            }
            $key = match ($filter) {
                'code' => 'codes', 'source' => 'sources', 'review_status' => 'review', default => $filter,
            };
            /** @var list<string> $columns */
            $columns = $definition[$key];
            if ($columns === []) {
                $query->whereRaw('1 = 0');

                continue;
            }
            $literal = str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                mb_strtolower((string) $validated[$filter]),
            );
            $needle = '%'.$literal.'%';
            $query->where(function (Builder $matches) use ($columns, $needle): void {
                foreach ($columns as $index => $column) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $matches->{$method}(
                        'LOWER('.$matches->getModel()->qualifyColumn($column).") LIKE ? ESCAPE '!'",
                        [$needle],
                    );
                }
            });
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @param  RecordCursor|null  $cursor
     */
    private function applyCursor(Builder $query, string $type, ?array $cursor, string $eventExpression, string $idColumn): void
    {
        if ($cursor === null) {
            return;
        }
        $typeOrder = strcmp($type, $cursor['resource_type']);
        $query->where(function (Builder $after) use ($cursor, $eventExpression, $idColumn, $typeOrder): void {
            $after->whereRaw("{$eventExpression} < ?", [$cursor['event_at']]);
            if ($typeOrder > 0) {
                $after->orWhereRaw("{$eventExpression} = ?", [$cursor['event_at']]);
            } elseif ($typeOrder === 0) {
                $after->orWhereRaw("{$eventExpression} = ? AND {$idColumn} < ?", [$cursor['event_at'], $cursor['id']]);
            }
        });
    }

    /**
     * @param  SearchDefinition  $definition
     * @return ProjectedRecord
     */
    private function project(string $type, array $definition, Model $record): array
    {
        $eventAt = (string) $record->getAttribute('agent_event_at');
        $first = function (array $columns) use ($record): ?string {
            foreach ($columns as $column) {
                $value = $record->getAttribute($column);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return (string) $value;
                }
            }

            return null;
        };
        $codes = [];
        foreach ($definition['codes'] as $column) {
            $value = $record->getAttribute($column);
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $item) {
                $code = is_array($item) ? ($item['code'] ?? null) : $item;
                if (is_scalar($code) && trim((string) $code) !== '') {
                    $codes[(string) $code] = (string) $code;
                }
            }
        }
        $payload = [
            'resource_type' => $type,
            'id' => (int) $record->getKey(),
            'patient_id' => (int) $record->getAttribute('patient_id'),
            'event_at' => Carbon::parse($eventAt)->toIso8601String(),
            'updated_at' => $record->getAttribute('updated_at')?->toIso8601String(),
            'summary' => $first($definition['summary']),
            'provider' => $first($definition['provider']),
            'facility' => $first($definition['facility']),
            'codes' => array_values($codes),
            'source' => $first($definition['sources']),
            'review_status' => $first($definition['review']),
            'source_document_id' => $definition['source_document']
                ? $record->getAttribute('source_document_id')
                : null,
        ];

        return [
            'cursor' => ['event_at' => $eventAt, 'resource_type' => $type, 'id' => (int) $record->getKey()],
            'payload' => $payload,
        ];
    }

    /**
     * @param  ProjectedRecord  $left
     * @param  ProjectedRecord  $right
     */
    private function compare(array $left, array $right): int
    {
        return strcmp($right['cursor']['event_at'], $left['cursor']['event_at'])
            ?: strcmp($left['cursor']['resource_type'], $right['cursor']['resource_type'])
            ?: ($right['cursor']['id'] <=> $left['cursor']['id']);
    }
}
