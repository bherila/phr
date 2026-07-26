<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrRespiratoryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RespiratoryEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrRespiratoryEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'phr_patient_id' => $event->phr_patient_id,
            'client_event_uuid' => $event->client_event_uuid,
            'event_type' => $event->event_type,
            'occurred_at' => $event->occurred_at->toDateTimeString(),
            'tz_offset_min' => $event->tz_offset_min,
            'duration_ms' => $event->duration_ms,
            'confidence' => $event->confidence,
            'burst_count' => $event->burst_count,
            'peak_dbfs' => $event->peak_dbfs,
            'mean_dbfs' => $event->mean_dbfs,
            'noise_floor_dbfs' => $event->noise_floor_dbfs,
            'source' => $event->source,
            'device_id' => $event->device_id,
            'model_version' => $event->model_version,
            'false_positive_at' => $event->false_positive_at?->toDateTimeString(),
            'corrected_to_event_type' => $event->corrected_to_event_type,
            'corrected_at' => $event->corrected_at?->toDateTimeString(),
            // The label this event counts under: a correction relabels a real
            // event rather than erasing it.
            'effective_event_type' => $event->effectiveEventType(),
            'created_at' => $event->created_at?->toDateTimeString(),
            'updated_at' => $event->updated_at?->toDateTimeString(),
        ];
    }
}
