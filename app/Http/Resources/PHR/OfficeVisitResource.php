<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrDicomStudy;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
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
            'import_source' => $visit->import_source,
            'external_id' => $visit->external_id,
            'source_document_id' => $visit->source_document_id,
            'review_status' => $visit->review_status,
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
            'eobs' => $this->whenLoaded(
                'eobs',
                fn () => EobSummaryResource::collection($visit->eobs)->resolve(),
            ),
            'imaging_studies' => $visit->relationLoaded('imagingStudies')
                ? $visit->imagingStudies
                    ->map(fn (PhrDicomStudy $study): array => [
                        'id' => $study->id,
                        'study_date' => $study->study_date?->toDateString(),
                        'description' => $study->description,
                        'modalities' => $study->modalities,
                        'accession_number' => $study->accession_number,
                    ])
                    ->values()
                    ->all()
                : [],
            'related_services' => $visit->relationLoaded('eobs')
                ? $visit->eobs
                    ->flatMap(fn (PhrEob $eob) => $eob->relationLoaded('lines') ? $eob->lines : collect())
                    ->sortBy(fn (PhrEobLine $line): string => sprintf('%s-%010d', $line->service_start?->toDateString() ?? '', $line->id))
                    ->map(fn (PhrEobLine $line): array => [
                        'id' => $line->id,
                        'procedure_code' => $line->procedure_code,
                        'code_type' => $line->code_type,
                        'description' => $line->description,
                        'service_start' => $line->service_start?->toDateString(),
                        'service_end' => $line->service_end?->toDateString(),
                    ])
                    ->values()
                    ->all()
                : [],
            'created_at' => $visit->created_at?->toDateTimeString(),
            'updated_at' => $visit->updated_at?->toDateTimeString(),
        ];
    }
}
