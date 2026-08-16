<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StoreRespiratoryEventBatchRequest;
use App\Http\Resources\PHR\RespiratoryEventResource;
use App\Models\PhrRespiratoryEvent;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Respiratory\PhrRespiratoryEventService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiUpdateWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AgentRespiratoryEventController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrRespiratoryEventService $events,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'occurred_after' => ['sometimes', 'date'],
            'occurred_before' => ['sometimes', 'date', 'after_or_equal:occurred_after'],
            'event_type' => ['sometimes', 'string', Rule::in(PhrRespiratoryEvent::EVENT_TYPES)],
            'include_false_positives' => ['sometimes', 'boolean'],
        ]);
        $actorId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->accessiblePatientWithCurrentGrant($patient, $actorId);
        $query = $this->events->query(
            $resolvedPatient->id,
            isset($validated['occurred_after']) ? (string) $validated['occurred_after'] : null,
            isset($validated['occurred_before']) ? (string) $validated['occurred_before'] : null,
            isset($validated['event_type']) ? (string) $validated['event_type'] : null,
            (bool) ($validated['include_false_positives'] ?? false),
        );
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('phr_patient_id'));
        $limit = (int) ($validated['limit'] ?? 25);
        $cursor = isset($validated['cursor']) ? (string) $validated['cursor'] : null;
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode($cursor),
        );

        return response()->json([
            'resource_type' => 'respiratory-event',
            'patient_id' => $resolvedPatient->id,
            'data' => $page->getCollection()
                ->map(fn (PhrRespiratoryEvent $event): array => (new RespiratoryEventResource($event))->resolve($request))
                ->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    public function batch(StoreRespiratoryEventBatchRequest $request, int $patient): JsonResponse
    {
        $actorId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $actorId);
        /** @var list<array<string, mixed>> $events */
        $events = $request->validated()['events'];

        return response()->json([
            'resource_type' => 'respiratory-event-batch',
            'patient_id' => $resolvedPatient->id,
            'results' => $this->events->ingestBatch($resolvedPatient->id, $events),
        ]);
    }
}
