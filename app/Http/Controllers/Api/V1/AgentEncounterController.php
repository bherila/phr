<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationTransition;
use App\Models\PhrOfficeVisit;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Encounters\PhrEncounterLinkBatch;
use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Services\PHR\Encounters\PhrLinkSuggestions;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\PHR\Validation\LinkSuggestionQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The records made during an encounter (#411): link and unlink one, and read the visit
 * with its linked records grouped by type, each group bounded and paged on its own, and
 * the medications its transitions name with those transitions (#414). Suggest the records
 * dated inside a stay and link the ones the person confirms, several at once (#450).
 */
final class AgentEncounterController extends Controller
{
    /** Group => the clinical resource id its records are serialized as (null: documents). */
    private const array GROUP_RESOURCES = [
        'lab_results' => 'lab-results',
        'vitals' => 'vitals',
        'medications' => 'medications',
        'procedures' => 'procedures',
        'documents' => null,
    ];

    /**
     * Groups of the medications a stay's transitions name (#414), each with its transitions
     * there: every home medication a transition names, and every order a new transition
     * names. Each transition is listed once, under its home medication when it has one.
     */
    private const array TRANSITION_GROUPS = ['home_medications', 'new_medications'];

    private const string DOCUMENT_SCOPE_MESSAGE = 'Linking a document requires documents:read.';

    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrEncounterLinks $links,
        private AgentClinicalRecordVersion $versions,
    ) {}

    public function link(Request $request, int $patient, int $visit): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', 'string', Rule::in(PhrEncounterLinks::types())],
            'resource_id' => ['required', 'integer', 'min:1'],
        ]);
        $type = (string) $validated['resource_type'];
        $this->assertTypeScope($request, $type);
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $outcome = $this->links->link((int) $resolved->id, $visit, $type, (int) $validated['resource_id']);

        return $this->linkResponse((int) $resolved->id, $outcome, $visit, $type, (int) $validated['resource_id']);
    }

    /**
     * Link several records at once (#450), each with its own outcome, as the person confirmed
     * them from office_visits.suggest_links. Each type needs what link_record needs: a
     * document without documents:read is refused on its own, and the others still link.
     */
    public function linkMany(Request $request, int $patient, int $visit, PhrEncounterLinkBatch $batch): JsonResponse
    {
        $validated = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:'.PhrEncounterLinkBatch::MAX_LINKS],
            'links.*' => ['required', 'array:resource_type,resource_id'],
            'links.*.resource_type' => ['required', 'string', Rule::in(PhrEncounterLinks::types())],
            'links.*.resource_id' => ['required', 'integer', 'min:1'],
        ]);
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $patientId = (int) $resolved->id;
        $items = array_values(array_map(
            static fn (array $link): array => ['type' => (string) $link['resource_type'], 'id' => (int) $link['resource_id']],
            (array) $validated['links'],
        ));

        $allowed = [];
        $refused = [];
        foreach ($items as $index => $item) {
            if ($this->typeInScope($request, $item['type'])) {
                $allowed[$index] = $item;
            } else {
                $refused[$index] = [
                    ...$item,
                    'outcome' => PhrEncounterLinkBatch::REFUSED,
                    'reason' => PhrEncounterLinkBatch::INSUFFICIENT_SCOPE,
                    'message' => self::DOCUMENT_SCOPE_MESSAGE,
                ];
            }
        }
        // The visit is checked even when every item was refused, so a missing visit is a 404.
        $linked = array_combine(array_keys($allowed), $batch->link($patientId, $visit, array_values($allowed)));
        $results = [];
        foreach (array_keys($items) as $index) {
            $result = $linked[$index] ?? $refused[$index];
            $results[] = [
                'resource_type' => $result['type'],
                'resource_id' => $result['id'],
                ...array_diff_key($result, ['type' => true, 'id' => true]),
            ];
        }
        $count = static fn (string $outcome): int => count(array_filter($results, static fn (array $result): bool => $result['outcome'] === $outcome));

        return response()->json([
            'resource_type' => 'office_visit_link_batch',
            'patient_id' => $patientId,
            'data' => [
                'office_visit_id' => $visit,
                'results' => $results,
                'summary' => [
                    PhrEncounterLinks::LINKED => $count(PhrEncounterLinks::LINKED),
                    PhrEncounterLinks::UNCHANGED => $count(PhrEncounterLinks::UNCHANGED),
                    PhrEncounterLinkBatch::REFUSED => $count(PhrEncounterLinkBatch::REFUSED),
                ],
            ],
        ]);
    }

    /**
     * The records dated inside a stay that no visit holds yet, and those another visit holds
     * (#450), for the person to confirm. It links nothing. Documents need documents:read, as
     * in get_stay: without it they are left out.
     */
    public function suggest(Request $request, int $patient, int $visit, PhrLinkSuggestions $suggestions): JsonResponse
    {
        $validated = LinkSuggestionQuery::validate($request);
        $patientId = (int) $this->accessService->accessiblePatientWithCurrentGrant($patient, (int) $request->user('api')?->id)->id;
        $resolved = PhrOfficeVisit::query()->where('patient_id', $patientId)->whereNull('retracted_at')->findOrFail($visit);
        $types = array_values(array_filter(
            $validated['types'] ?? PhrEncounterLinks::types(),
            fn (string $type): bool => $this->typeInScope($request, $type),
        ));
        $suggested = $suggestions->suggest($patientId, $resolved, $types, $validated['margin_days'], $validated['limit'], $validated['list'], $validated['cursor']);

        return response()->json([
            'resource_type' => 'office_visit_link_suggestions',
            'patient_id' => $patientId,
            'data' => [
                'office_visit_id' => (int) $resolved->id,
                'window' => $suggested['window'],
                'groups' => (object) $suggested['groups'],
            ],
        ]);
    }

    public function unlink(Request $request, int $patient, int $visit, string $type, int $record): JsonResponse
    {
        abort_unless(in_array($type, PhrEncounterLinks::types(), true), 404);
        $this->assertTypeScope($request, $type);
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $outcome = $this->links->unlink((int) $resolved->id, $visit, $type, $record);

        return $this->linkResponse((int) $resolved->id, $outcome, $visit, $type, $record);
    }

    public function stay(Request $request, int $patient, int $visit): JsonResponse
    {
        $validated = $request->validate([
            'group' => ['sometimes', 'string', Rule::in([...array_keys(self::GROUP_RESOURCES), ...self::TRANSITION_GROUPS])],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ]);
        if (isset($validated['cursor']) && ! isset($validated['group'])) {
            throw ValidationException::withMessages(['cursor' => 'A cursor pages one group: send the group it came from.']);
        }
        $patientId = (int) $this->accessService->accessiblePatientWithCurrentGrant($patient, (int) $request->user('api')?->id)->id;
        $resolved = PhrOfficeVisit::query()->where('patient_id', $patientId)->whereNull('retracted_at')->findOrFail($visit);
        $limit = (int) ($validated['limit'] ?? 10);
        $documents = (bool) $request->user('api')?->tokenCan(AgentApiScopes::DOCUMENTS_READ);

        $groups = [];
        foreach (self::GROUP_RESOURCES as $group => $resource) {
            if (isset($validated['group']) && $validated['group'] !== $group) {
                continue;
            }
            if ($resource === null && ! $documents) {
                continue;
            }
            $cursor = isset($validated['group']) && isset($validated['cursor']) ? (string) $validated['cursor'] : null;
            $groups[$group] = $this->group($request, $patientId, (int) $resolved->id, $group, $resource, $limit, $cursor);
        }
        foreach (self::TRANSITION_GROUPS as $group) {
            if (isset($validated['group']) && $validated['group'] !== $group) {
                continue;
            }
            $cursor = isset($validated['group']) && isset($validated['cursor']) ? (string) $validated['cursor'] : null;
            $groups[$group] = $this->transitionGroup($request, $patientId, (int) $resolved->id, $group, $limit, $cursor);
        }

        return response()->json([
            'resource_type' => 'office_visit_stay',
            'patient_id' => $patientId,
            'data' => [
                'office_visit' => $this->clinicalPayload($request, 'office-visits', $resolved, [
                    'linked_records' => $this->links->counts($patientId, [(int) $resolved->id])[(int) $resolved->id],
                ]),
                'groups' => (object) $groups,
            ],
        ]);
    }

    /**
     * @return array{resource_type: string, data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    private function group(Request $request, int $patientId, int $visitId, string $group, ?string $resource, int $limit, ?string $cursor): array
    {
        $type = $resource === null ? 'document' : PhrEncounterLinks::CLINICAL_RESOURCES[$resource];
        $query = $this->links->linkedRecords($patientId, $visitId, $type);
        if ($resource === null) {
            // Its link is part of each document's payload and version.
            $query->with(['genAiJob:id,status', 'relatesTo']);
        }
        $page = $query->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', AgentApiCursor::decode($cursor));
        $records = $page->getCollection();

        if ($resource === null) {
            /** @var Collection<int, PhrDocument> $records */
            $data = app(AgentDocumentController::class)->documentResources($patientId, $records);
        } else {
            $data = $records->map(fn (Model $record): array => $this->clinicalPayload($request, $resource, $record, ['encounter_id' => $visitId]))->values()->all();
        }

        return [
            'resource_type' => $resource ?? 'document',
            'data' => $data,
            'pagination' => ['limit' => $limit, 'has_more' => $page->hasMorePages(), 'next_cursor' => $page->nextCursor()?->encode()],
        ];
    }

    /**
     * The medications a stay's transitions name, paged by id, each as clinical.get reads it
     * with `transitions`: its standing transitions in this stay, admission first.
     *
     * @return array{resource_type: string, data: list<array<string, mixed>>, pagination: array{limit: int, has_more: bool, next_cursor: string|null}}
     */
    private function transitionGroup(Request $request, int $patientId, int $visitId, string $group, int $limit, ?string $cursor): array
    {
        $column = $group === 'home_medications' ? 'home_medication_id' : 'order_medication_id';
        $standing = static fn (QueryBuilder $query): QueryBuilder => $query->from('phr_medication_transitions')
            ->where('patient_id', $patientId)->where('office_visit_id', $visitId)
            ->whereNull('deleted_at')->whereNull('retracted_at');
        $page = PhrMedication::query()
            ->where('patient_id', $patientId)
            ->whereNull('retracted_at')
            ->whereIn('id', fn (QueryBuilder $query) => $standing($query)->select($column)->whereNotNull($column)
                ->when($group === 'new_medications', static fn (QueryBuilder $new) => $new->where('action', PhrMedicationTransition::NEW)))
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', AgentApiCursor::decode($cursor));
        $medications = $page->getCollection();
        $ids = $medications->modelKeys();

        $transitions = PhrMedicationTransition::query()
            ->where('patient_id', $patientId)
            ->where('office_visit_id', $visitId)
            ->whereNull('retracted_at')
            ->whereIn($column, $ids)
            ->when($group === 'new_medications', static fn ($new) => $new->where('action', PhrMedicationTransition::NEW))
            ->orderBy('id')
            ->get()
            ->sortBy(static fn (PhrMedicationTransition $transition): array => [array_search($transition->phase, PhrMedicationTransition::PHASES, true), $transition->id])
            ->groupBy($column);
        $encounters = $this->links->encounterIds($patientId, 'medication', $ids);

        $data = $medications->map(fn (Model $medication): array => [
            ...$this->clinicalPayload($request, 'medications', $medication, ['encounter_id' => $encounters[(int) $medication->getKey()] ?? null]),
            'transitions' => ($transitions[(int) $medication->getKey()] ?? collect())
                ->map(fn (PhrMedicationTransition $transition): array => $this->clinicalPayload($request, 'medication-transitions', $transition, []))
                ->values()->all(),
        ])->values()->all();

        return [
            'resource_type' => 'medications',
            'data' => $data,
            'pagination' => ['limit' => $limit, 'has_more' => $page->hasMorePages(), 'next_cursor' => $page->nextCursor()?->encode()],
        ];
    }

    /**
     * A record as clinical.get serializes it: the resource, its encounter context, its version.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function clinicalPayload(Request $request, string $resource, Model $record, array $context): array
    {
        $definition = AgentClinicalResourceCatalog::definition($resource) ?? abort(500);
        $resourceClass = $definition['resource'];

        return [...(new $resourceClass($record))->resolve($request), ...$context, 'version' => $this->versions->for($record)];
    }

    /** A document link reads and names a document, so it needs documents:read as well. */
    private function assertTypeScope(Request $request, string $type): void
    {
        abort_unless($this->typeInScope($request, $type), 403, self::DOCUMENT_SCOPE_MESSAGE);
    }

    private function typeInScope(Request $request, string $type): bool
    {
        return $type !== 'document' || (bool) $request->user('api')?->tokenCan(AgentApiScopes::DOCUMENTS_READ);
    }

    private function linkResponse(int $patientId, string $outcome, int $visitId, string $type, int $recordId): JsonResponse
    {
        return response()->json([
            'resource_type' => 'office_visit_link',
            'patient_id' => $patientId,
            'outcome' => $outcome,
            'data' => ['office_visit_id' => $visitId, 'resource_type' => $type, 'resource_id' => $recordId],
        ]);
    }
}
