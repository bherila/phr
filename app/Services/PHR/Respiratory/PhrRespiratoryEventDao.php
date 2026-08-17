<?php

namespace App\Services\PHR\Respiratory;

use App\Models\PhrRespiratoryEvent;
use Illuminate\Database\Eloquent\Builder;

/** Typed storage boundary for Sinus Sentinel event synchronization. */
final class PhrRespiratoryEventDao
{
    /** @return Builder<PhrRespiratoryEvent> */
    public function query(int $patientId): Builder
    {
        return PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId);
    }

    /**
     * @param  list<string>  $candidateUuids
     * @return array<string, true>
     */
    public function existingUuids(int $patientId, array $candidateUuids): array
    {
        if ($candidateUuids === []) {
            return [];
        }

        $map = [];
        foreach ($this->query($patientId)
            ->whereIn('client_event_uuid', array_values(array_unique($candidateUuids)))
            ->pluck('client_event_uuid')->all() as $uuid) {
            $map[(string) $uuid] = true;
        }

        return $map;
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): PhrRespiratoryEvent
    {
        return PhrRespiratoryEvent::query()->create($attributes);
    }

    public function byUuid(int $patientId, string $uuid): ?PhrRespiratoryEvent
    {
        return $this->query($patientId)->where('client_event_uuid', $uuid)->first();
    }

    /**
     * @param  list<string>  $uuids
     * @return list<string>
     */
    public function deleteByUuids(int $patientId, array $uuids): array
    {
        $deleted = $this->query($patientId)
            ->whereIn('client_event_uuid', $uuids)
            ->pluck('client_event_uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->values()
            ->all();

        if ($deleted !== []) {
            $this->query($patientId)->whereIn('client_event_uuid', $deleted)->delete();
        }

        return $deleted;
    }
}
