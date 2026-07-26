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
            'created_at' => $medication->created_at?->toDateTimeString(),
            'updated_at' => $medication->updated_at?->toDateTimeString(),
        ];
    }
}
