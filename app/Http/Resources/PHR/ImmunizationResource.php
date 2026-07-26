<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrImmunization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImmunizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrImmunization $immunization */
        $immunization = $this->resource;

        return [
            'id' => $immunization->id,
            'patient_id' => $immunization->patient_id,
            'user_id' => $immunization->user_id,
            'vaccine_name' => $immunization->vaccine_name,
            'cvx_code' => $immunization->cvx_code,
            'manufacturer' => $immunization->manufacturer,
            'lot_number' => $immunization->lot_number,
            'administered_on' => $immunization->administered_on?->toDateString(),
            'dose_number' => $immunization->dose_number,
            'series_doses' => $immunization->series_doses,
            'site' => $immunization->site,
            'route' => $immunization->route,
            'administered_by' => $immunization->administered_by,
            'facility_name' => $immunization->facility_name,
            'notes' => $immunization->notes,
            'raw_text' => $immunization->raw_text,
            'created_at' => $immunization->created_at?->toDateTimeString(),
            'updated_at' => $immunization->updated_at?->toDateTimeString(),
        ];
    }
}
