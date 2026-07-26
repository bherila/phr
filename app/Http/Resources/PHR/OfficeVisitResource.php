<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrOfficeVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrOfficeVisit $visit */
        $visit = $this->resource;

        return [
            'id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'user_id' => $visit->user_id,
            'visit_date' => $visit->visit_date?->toDateString(),
            'visit_started_at' => $visit->visit_started_at?->toDateTimeString(),
            'visit_ended_at' => $visit->visit_ended_at?->toDateTimeString(),
            'visit_type' => $visit->visit_type,
            'provider_name' => $visit->provider_name,
            'provider_specialty' => $visit->provider_specialty,
            'facility_name' => $visit->facility_name,
            'chief_complaint' => $visit->chief_complaint,
            'assessment' => $visit->assessment,
            'plan' => $visit->plan,
            'subjective' => $visit->subjective,
            'objective' => $visit->objective,
            'icd10_codes' => $visit->icd10_codes ?? [],
            'cpt_codes' => $visit->cpt_codes ?? [],
            'created_at' => $visit->created_at?->toDateTimeString(),
            'updated_at' => $visit->updated_at?->toDateTimeString(),
        ];
    }
}
