<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DeleteRespiratoryEventBatchRequest;
use App\Http\Requests\PHR\FlagRespiratoryEventBatchRequest;
use App\Http\Requests\PHR\StoreRespiratoryEventBatchRequest;
use App\Http\Resources\PHR\RespiratoryEventResource;
use App\Models\PhrRespiratoryEvent;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RespiratoryEventController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    /**
     * Idempotent batch ingest. Each event is validated and inserted
     * individually; existing `client_event_uuid`s (per patient) yield
     * `duplicate`, invalid events yield `rejected`. The request always
     * returns 200 with per-event results.
     */
    public function batch(StoreRespiratoryEventBatchRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        /** @var list<array<string, mixed>> $events */
        $events = $request->validated()['events'];

        $results = [];
        $seenUuids = [];

        DB::transaction(function () use ($events, $resolvedPatient, &$results, &$seenUuids): void {
            $existingUuids = $this->existingUuids($resolvedPatient->id, $events);

            foreach ($events as $event) {
                $results[] = $this->ingestEvent(
                    $event,
                    $resolvedPatient->id,
                    $existingUuids,
                    $seenUuids,
                );
            }
        });

        return response()->json(['results' => $results]);
    }

    /**
     * Tombstone sync. Deletes events by `client_event_uuid`; already-absent
     * uuids report `not_found`. Idempotent and always 200.
     */
    public function deleteBatch(DeleteRespiratoryEventBatchRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        /** @var list<string> $uuids */
        $uuids = array_values(array_unique($request->validated()['uuids']));

        $deleted = PhrRespiratoryEvent::query()
            ->where('phr_patient_id', $resolvedPatient->id)
            ->whereIn('client_event_uuid', $uuids)
            ->pluck('client_event_uuid')
            ->all();

        if ($deleted !== []) {
            PhrRespiratoryEvent::query()
                ->where('phr_patient_id', $resolvedPatient->id)
                ->whereIn('client_event_uuid', $deleted)
                ->delete();
        }

        $deletedSet = array_flip($deleted);

        $results = array_map(fn (string $uuid): array => [
            'uuid' => $uuid,
            'status' => isset($deletedSet[$uuid]) ? 'deleted' : 'not_found',
        ], $uuids);

        return response()->json([
            'deleted' => count($deleted),
            'results' => $results,
        ]);
    }

    /**
     * Mark (or unmark) events the user reported as misdetections, and record
     * corrections.
     *
     * Kept separate from `batch` deliberately: the insert path stays purely
     * idempotent rather than growing upsert semantics. The payload is fully
     * declarative, so clearing a flag — the device's Undo — is just
     * `false_positive: false` with a null `corrected_to`.
     *
     * Unknown uuids report `not_found` rather than erroring: the device treats
     * that as terminal so a flag on an event the server never accepted cannot
     * loop forever.
     */
    public function flagBatch(FlagRespiratoryEventBatchRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        /** @var list<array{uuid: string, false_positive: bool, corrected_to: string|null}> $items */
        $items = $request->validated()['items'];

        $results = [];

        DB::transaction(function () use ($items, $resolvedPatient, &$results): void {
            $now = now();

            foreach ($items as $item) {
                $event = PhrRespiratoryEvent::query()
                    ->where('phr_patient_id', $resolvedPatient->id)
                    ->where('client_event_uuid', $item['uuid'])
                    ->first();

                if ($event === null) {
                    $results[] = ['uuid' => $item['uuid'], 'status' => 'not_found'];

                    continue;
                }

                $correctedTo = $item['corrected_to'] ?? null;

                $event->false_positive_at = $item['false_positive'] ? $now : null;
                $event->corrected_to_event_type = $correctedTo;
                $event->corrected_at = $correctedTo === null ? null : $now;
                $event->save();

                $results[] = ['uuid' => $item['uuid'], 'status' => 'flagged'];
            }
        });

        return response()->json([
            'flagged' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'flagged')),
            'results' => $results,
        ]);
    }

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $query = PhrRespiratoryEvent::query()->where('phr_patient_id', $resolvedPatient->id);

        if (! $this->includesFalsePositives($request)) {
            $query->excludingFalsePositives();
        }

        if (($from = $request->query('from')) !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if (($to = $request->query('to')) !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        if (($type = $request->query('type')) !== null) {
            // Match the label the event actually counts under, so filtering for
            // `nose_blow` finds a cough the user recharacterised as a nose blow
            // and does not find a nose blow they recharacterised away.
            $query->whereRaw('COALESCE(corrected_to_event_type, event_type) = ?', [$type]);
        }

        /** @var Collection<int, PhrRespiratoryEvent> $events */
        $events = $query->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return response()->json([
            'respiratory_events' => RespiratoryEventResource::collection($events)->resolve(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    /**
     * Day-bucketed counts. Days are computed in the event's own local time
     * (occurred_at shifted by tz_offset_min) so a symptom day matches what the
     * device recorded, regardless of server timezone.
     */
    public function summary(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $query = PhrRespiratoryEvent::query()
            ->where('phr_patient_id', $resolvedPatient->id)
            ->excludingFalsePositives();

        if (($from = $request->query('from')) !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if (($to = $request->query('to')) !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        /** @var Collection<int, PhrRespiratoryEvent> $events */
        $events = $query->orderBy('occurred_at')->get();

        $buckets = [];

        foreach ($events as $event) {
            $localDate = $event->occurred_at->copy()->addMinutes($event->tz_offset_min)->toDateString();

            if (! isset($buckets[$localDate])) {
                $buckets[$localDate] = [
                    'date' => $localDate,
                    'count' => 0,
                    'burst_total' => 0,
                    'by_type' => [],
                ];
            }

            $type = $event->effectiveEventType();

            $buckets[$localDate]['count']++;
            $buckets[$localDate]['burst_total'] += $event->burst_count;
            $buckets[$localDate]['by_type'][$type]
                = ($buckets[$localDate]['by_type'][$type] ?? 0) + 1;
        }

        ksort($buckets);

        return response()->json([
            'bucket' => 'day',
            'buckets' => array_values($buckets),
        ]);
    }

    /**
     * Known misdetections are hidden from reads by default. Auditing them is
     * opt-in.
     */
    private function includesFalsePositives(Request $request): bool
    {
        return filter_var(
            $request->query('include_false_positives', '0'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, true>
     */
    private function existingUuids(int $patientId, array $events): array
    {
        $candidateUuids = [];

        foreach ($events as $event) {
            if (isset($event['client_event_uuid']) && is_string($event['client_event_uuid'])) {
                $candidateUuids[] = $event['client_event_uuid'];
            }
        }

        if ($candidateUuids === []) {
            return [];
        }

        $map = [];

        foreach (
            PhrRespiratoryEvent::query()
                ->where('phr_patient_id', $patientId)
                ->whereIn('client_event_uuid', array_values(array_unique($candidateUuids)))
                ->pluck('client_event_uuid')
                ->all() as $uuid
        ) {
            $map[(string) $uuid] = true;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, true>  $existingUuids
     * @param  array<string, true>  $seenUuids
     * @return array{uuid: string|null, status: string, reason?: string}
     */
    private function ingestEvent(array $event, int $patientId, array $existingUuids, array &$seenUuids): array
    {
        $validator = Validator::make($event, $this->eventRules());

        if ($validator->fails()) {
            $uuid = isset($event['client_event_uuid']) && is_string($event['client_event_uuid'])
                ? $event['client_event_uuid']
                : null;

            return [
                'uuid' => $uuid,
                'status' => 'rejected',
                'reason' => (string) $validator->errors()->first(),
            ];
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        /** @var string $uuid */
        $uuid = $data['client_event_uuid'];

        if (isset($existingUuids[$uuid]) || isset($seenUuids[$uuid])) {
            return ['uuid' => $uuid, 'status' => 'duplicate'];
        }

        $seenUuids[$uuid] = true;

        try {
            PhrRespiratoryEvent::query()->create([
                'phr_patient_id' => $patientId,
                'client_event_uuid' => $uuid,
                'event_type' => $data['event_type'],
                'occurred_at' => $data['occurred_at'],
                'tz_offset_min' => $data['tz_offset_min'] ?? 0,
                'duration_ms' => $data['duration_ms'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'burst_count' => $data['burst_count'] ?? 1,
                'peak_dbfs' => $data['peak_dbfs'] ?? null,
                'mean_dbfs' => $data['mean_dbfs'] ?? null,
                'noise_floor_dbfs' => $data['noise_floor_dbfs'] ?? null,
                'source' => $data['source'] ?? null,
                'device_id' => $data['device_id'] ?? null,
                'model_version' => $data['model_version'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['uuid' => $uuid, 'status' => 'duplicate'];
        }

        return ['uuid' => $uuid, 'status' => 'accepted'];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRules(): array
    {
        return [
            'client_event_uuid' => ['required', 'string', 'max:64'],
            'event_type' => ['required', 'string', 'in:'.implode(',', PhrRespiratoryEvent::EVENT_TYPES)],
            'occurred_at' => ['required', 'date'],
            'tz_offset_min' => ['nullable', 'integer', 'between:-720,840'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'burst_count' => ['nullable', 'integer', 'min:1'],
            // dBFS. Real signal is <= 0; the range leaves headroom for clipping
            // above and for a very low adaptive noise floor below.
            'peak_dbfs' => ['nullable', 'numeric', 'between:-120,20'],
            'mean_dbfs' => ['nullable', 'numeric', 'between:-120,20'],
            'noise_floor_dbfs' => ['nullable', 'numeric', 'between:-120,20'],
            'source' => ['nullable', 'string', 'in:'.implode(',', PhrRespiratoryEvent::SOURCES)],
            'device_id' => ['nullable', 'string', 'max:64'],
            'model_version' => ['nullable', 'string', 'max:64'],
        ];
    }
}
