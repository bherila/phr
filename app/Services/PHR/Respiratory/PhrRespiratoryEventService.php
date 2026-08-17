<?php

namespace App\Services\PHR\Respiratory;

use App\Models\PhrRespiratoryEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Shared browser, device, and OAuth-agent respiratory event behavior. */
final class PhrRespiratoryEventService
{
    public function __construct(private PhrRespiratoryEventDao $events) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array{uuid: string|null, status: string, reason?: string}>
     */
    public function ingestBatch(int $patientId, array $events): array
    {
        return DB::transaction(function () use ($patientId, $events): array {
            $candidateUuids = [];
            foreach ($events as $event) {
                if (isset($event['client_event_uuid']) && is_string($event['client_event_uuid'])) {
                    $candidateUuids[] = $event['client_event_uuid'];
                }
            }
            $existingUuids = $this->events->existingUuids($patientId, $candidateUuids);
            $seenUuids = [];
            $results = [];

            foreach ($events as $event) {
                $results[] = $this->ingestEvent($event, $patientId, $existingUuids, $seenUuids);
            }

            return $results;
        });
    }

    /**
     * @param  list<string>  $uuids
     * @return array{deleted: int, results: list<array{uuid: string, status: string}>}
     */
    public function deleteBatch(int $patientId, array $uuids): array
    {
        $uuids = array_values(array_unique($uuids));
        $deleted = $this->events->deleteByUuids($patientId, $uuids);
        $deletedSet = array_flip($deleted);

        return [
            'deleted' => count($deleted),
            'results' => array_map(static fn (string $uuid): array => [
                'uuid' => $uuid,
                'status' => isset($deletedSet[$uuid]) ? 'deleted' : 'not_found',
            ], $uuids),
        ];
    }

    /**
     * @param  list<array{uuid: string, false_positive: bool, corrected_to: string|null}>  $items
     * @return array{flagged: int, results: list<array{uuid: string, status: string}>}
     */
    public function flagBatch(int $patientId, array $items): array
    {
        return DB::transaction(function () use ($patientId, $items): array {
            $results = [];
            $now = now();
            foreach ($items as $item) {
                $event = $this->events->byUuid($patientId, $item['uuid']);
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

            return [
                'flagged' => count(array_filter($results, static fn (array $result): bool => $result['status'] === 'flagged')),
                'results' => $results,
            ];
        });
    }

    /** @return Builder<PhrRespiratoryEvent> */
    public function query(
        int $patientId,
        ?string $from = null,
        ?string $to = null,
        ?string $type = null,
        bool $includeFalsePositives = false,
    ): Builder {
        $query = $this->events->query($patientId);
        if (! $includeFalsePositives) {
            $query->excludingFalsePositives();
        }
        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }
        if ($type !== null) {
            $query->whereRaw('COALESCE(corrected_to_event_type, event_type) = ?', [$type]);
        }

        return $query;
    }

    /** @return list<array{date: string, count: int, burst_total: int, by_type: array<string, int>}> */
    public function dailySummary(int $patientId, ?string $from = null, ?string $to = null): array
    {
        $events = $this->query($patientId, $from, $to)->orderBy('occurred_at')->get();
        $buckets = [];
        foreach ($events as $event) {
            $localDate = $event->occurred_at->copy()->addMinutes($event->tz_offset_min)->toDateString();
            $buckets[$localDate] ??= [
                'date' => $localDate,
                'count' => 0,
                'burst_total' => 0,
                'by_type' => [],
            ];
            $type = $event->effectiveEventType();
            $buckets[$localDate]['count']++;
            $buckets[$localDate]['burst_total'] += $event->burst_count;
            $buckets[$localDate]['by_type'][$type]
                = ($buckets[$localDate]['by_type'][$type] ?? 0) + 1;
        }
        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, true>  $existingUuids
     * @param  array<string, true>  $seenUuids
     * @return array{uuid: string|null, status: string, reason?: string}
     */
    private function ingestEvent(array $event, int $patientId, array $existingUuids, array &$seenUuids): array
    {
        $rules = self::eventRules();
        if (array_diff_key($event, $rules) !== []) {
            return [
                'uuid' => isset($event['client_event_uuid']) && is_string($event['client_event_uuid'])
                    ? $event['client_event_uuid'] : null,
                'status' => 'rejected',
                'reason' => 'The event contains unsupported fields.',
            ];
        }
        $validator = Validator::make($event, $rules);
        if ($validator->fails()) {
            return [
                'uuid' => isset($event['client_event_uuid']) && is_string($event['client_event_uuid'])
                    ? $event['client_event_uuid'] : null,
                'status' => 'rejected',
                'reason' => (string) $validator->errors()->first(),
            ];
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        $uuid = (string) $data['client_event_uuid'];
        if (isset($existingUuids[$uuid]) || isset($seenUuids[$uuid])) {
            return ['uuid' => $uuid, 'status' => 'duplicate'];
        }
        $seenUuids[$uuid] = true;

        try {
            $this->events->create([
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

    /** @return array<string, mixed> */
    public static function eventRules(): array
    {
        return [
            'client_event_uuid' => ['required', 'string', 'max:64'],
            'event_type' => ['required', 'string', 'in:'.implode(',', PhrRespiratoryEvent::EVENT_TYPES)],
            'occurred_at' => ['required', 'date'],
            'tz_offset_min' => ['nullable', 'integer', 'between:-720,840'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'burst_count' => ['nullable', 'integer', 'min:1'],
            'peak_dbfs' => ['nullable', 'numeric', 'between:-120,20'],
            'mean_dbfs' => ['nullable', 'numeric', 'between:-120,20'],
            'noise_floor_dbfs' => ['nullable', 'numeric', 'between:-120,20'],
            'source' => ['nullable', 'string', 'in:'.implode(',', PhrRespiratoryEvent::SOURCES)],
            'device_id' => ['nullable', 'string', 'max:64'],
            'model_version' => ['nullable', 'string', 'max:64'],
        ];
    }
}
