<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrProcedure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcedureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrProcedure $procedure */
        $procedure = $this->resource;

        return [
            'id' => $procedure->id,
            'patient_id' => $procedure->patient_id,
            'user_id' => $procedure->user_id,
            'name' => $procedure->name,
            'cpt_code' => $procedure->cpt_code,
            'snomed_code' => $procedure->snomed_code,
            'performed_at' => $procedure->performed_at?->toDateTimeString(),
            'performed_on' => $procedure->performed_on?->toDateString(),
            'performer_name' => $procedure->performer_name,
            'performer_specialty' => $procedure->performer_specialty,
            'facility_name' => $procedure->facility_name,
            'status' => $procedure->status,
            'reason' => $procedure->reason,
            'outcome' => $procedure->outcome,
            'notes' => $procedure->notes,
            'raw_text' => $procedure->raw_text,
            'created_at' => $procedure->created_at?->toDateTimeString(),
            'updated_at' => $procedure->updated_at?->toDateTimeString(),
        ];
    }
}
