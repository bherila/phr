<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrAllergy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AllergyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrAllergy $allergy */
        $allergy = $this->resource;

        return [
            'id' => $allergy->id,
            'patient_id' => $allergy->patient_id,
            'user_id' => $allergy->user_id,
            'substance' => $allergy->substance,
            'rxnorm_code' => $allergy->rxnorm_code,
            'snomed_code' => $allergy->snomed_code,
            'category' => $allergy->category,
            'criticality' => $allergy->criticality,
            'clinical_status' => $allergy->clinical_status,
            'verification_status' => $allergy->verification_status,
            'reaction' => $allergy->reaction,
            'severity' => $allergy->severity,
            'notes' => $allergy->notes,
            'raw_text' => $allergy->raw_text,
            'created_at' => $allergy->created_at?->toDateTimeString(),
            'updated_at' => $allergy->updated_at?->toDateTimeString(),
        ];
    }
}
