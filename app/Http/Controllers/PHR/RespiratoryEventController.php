<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DeleteRespiratoryEventBatchRequest;
use App\Http\Requests\PHR\FlagRespiratoryEventBatchRequest;
use App\Http\Requests\PHR\StoreRespiratoryEventBatchRequest;
use App\Http\Resources\PHR\RespiratoryEventResource;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Respiratory\PhrRespiratoryEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RespiratoryEventController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrRespiratoryEventService $events,
    ) {}

    public function batch(StoreRespiratoryEventBatchRequest $request, int $patient): JsonResponse
    {
        $resolvedPatient = $this->accessService->writablePatient($patient, (int) $request->user()?->id);
        /** @var list<array<string, mixed>> $events */
        $events = $request->validated()['events'];

        return response()->json(['results' => $this->events->ingestBatch($resolvedPatient->id, $events)]);
    }

    public function deleteBatch(DeleteRespiratoryEventBatchRequest $request, int $patient): JsonResponse
    {
        $resolvedPatient = $this->accessService->writablePatient($patient, (int) $request->user()?->id);
        /** @var list<string> $uuids */
        $uuids = $request->validated()['uuids'];

        return response()->json($this->events->deleteBatch($resolvedPatient->id, $uuids));
    }

    public function flagBatch(FlagRespiratoryEventBatchRequest $request, int $patient): JsonResponse
    {
        $resolvedPatient = $this->accessService->writablePatient($patient, (int) $request->user()?->id);
        /** @var list<array{uuid: string, false_positive: bool, corrected_to: string|null}> $items */
        $items = $request->validated()['items'];

        return response()->json($this->events->flagBatch($resolvedPatient->id, $items));
    }

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $events = $this->events->query(
            $resolvedPatient->id,
            $this->queryString($request, 'from'),
            $this->queryString($request, 'to'),
            $this->queryString($request, 'type'),
            $this->includesFalsePositives($request),
        )->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return response()->json([
            'respiratory_events' => RespiratoryEventResource::collection($events)->resolve(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function summary(Request $request, int $patient): JsonResponse
    {
        $resolvedPatient = $this->accessService->accessiblePatient($patient, (int) $request->user()?->id);

        return response()->json([
            'bucket' => 'day',
            'buckets' => $this->events->dailySummary(
                $resolvedPatient->id,
                $this->queryString($request, 'from'),
                $this->queryString($request, 'to'),
            ),
        ]);
    }

    private function includesFalsePositives(Request $request): bool
    {
        return filter_var($request->query('include_false_positives', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
