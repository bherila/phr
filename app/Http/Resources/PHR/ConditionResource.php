<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrCondition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConditionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrCondition $condition */
        $condition = $this->resource;

        return [
            'id' => $condition->id,
            'patient_id' => $condition->patient_id,
            'user_id' => $condition->user_id,
            'name' => $condition->name,
            'icd10_code' => $condition->icd10_code,
            'snomed_code' => $condition->snomed_code,
            'onset_date' => $condition->onset_date?->toDateString(),
            'abated_date' => $condition->abated_date?->toDateString(),
            'clinical_status' => $condition->clinical_status,
            'verification_status' => $condition->verification_status,
            'severity' => $condition->severity,
            'notes' => $condition->notes,
            'raw_text' => $condition->raw_text,
            'created_at' => $condition->created_at?->toDateTimeString(),
            'updated_at' => $condition->updated_at?->toDateTimeString(),
        ];
    }
}
