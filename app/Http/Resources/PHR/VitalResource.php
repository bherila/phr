<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrPatientVital;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VitalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrPatientVital $vital */
        $vital = $this->resource;

        return [
            'id' => $vital->id,
            'patient_id' => $vital->patient_id,
            'user_id' => $vital->user_id,
            'vital_name' => $vital->vital_name,
            'vital_date' => $vital->vital_date?->toDateString(),
            'observed_at' => $vital->observed_at?->toDateTimeString(),
            'vital_value' => $vital->vital_value,
            'value_numeric' => $vital->value_numeric,
            'value_numeric_secondary' => $vital->value_numeric_secondary,
            'unit' => $vital->unit,
            'secondary_unit' => $vital->secondary_unit,
            'body_site' => $vital->body_site,
            'source' => $vital->source,
            'notes' => $vital->notes,
            'created_at' => $vital->created_at?->toDateTimeString(),
            'updated_at' => $vital->updated_at?->toDateTimeString(),
        ];
    }
}
