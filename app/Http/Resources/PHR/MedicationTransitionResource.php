<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrMedicationTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicationTransitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrMedicationTransition $transition */
        $transition = $this->resource;

        return [
            'id' => $transition->id,
            'patient_id' => $transition->patient_id,
            'user_id' => $transition->user_id,
            'import_source' => $transition->import_source,
            'external_id' => $transition->external_id,
            'source_document_id' => $transition->source_document_id,
            'review_status' => $transition->review_status,
            'office_visit_id' => $transition->office_visit_id,
            'home_medication_id' => $transition->home_medication_id,
            'order_medication_id' => $transition->order_medication_id,
            'phase' => $transition->phase,
            'action' => $transition->action,
            'note' => $transition->note,
            'created_at' => $transition->created_at?->toDateTimeString(),
            'updated_at' => $transition->updated_at?->toDateTimeString(),
        ];
    }
}
