<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrMedication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrMedication $medication */
        $medication = $this->resource;

        return [
            'id' => $medication->id,
            'patient_id' => $medication->patient_id,
            'user_id' => $medication->user_id,
            'import_source' => $medication->import_source,
            'external_id' => $medication->external_id,
            'source_document_id' => $medication->source_document_id,
            'review_status' => $medication->review_status,
            'name' => $medication->name,
            'rxnorm_code' => $medication->rxnorm_code,
            'dose' => $medication->dose,
            'dose_unit' => $medication->dose_unit,
            'route' => $medication->route,
            'frequency' => $medication->frequency,
            'started_on' => $medication->started_on?->toDateString(),
            'ended_on' => $medication->ended_on?->toDateString(),
            'status' => $medication->status,
            'prescriber_name' => $medication->prescriber_name,
            'reason_for_use' => $medication->reason_for_use,
            'raw_text' => $medication->raw_text,
            'category' => $medication->category,
            'as_needed' => $medication->as_needed,
            'as_needed_reason' => $medication->as_needed_reason,
            'brand_name' => $medication->brand_name,
            'rate' => $medication->rate,
            'started_at' => $medication->started_at?->toDateTimeString(),
            'ended_at' => $medication->ended_at?->toDateTimeString(),
            'review_flags' => $medication->review_flags ?? [],
            'created_at' => $medication->created_at?->toDateTimeString(),
            'updated_at' => $medication->updated_at?->toDateTimeString(),
        ];
    }
}
