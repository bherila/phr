<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrMedicationTransition;
use App\Models\PhrOfficeVisit;
use App\Models\PhrOfficeVisitParticipant;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeVisitResource extends JsonResource
{
    /** How many records of each kind a visit page previews (#411). */
    public const int LINKED_PREVIEW_LIMIT = 100;

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
            'encounter_category' => $visit->encounter_category,
            'encounter_status' => $visit->encounter_status,
            'encounter_diagnoses' => $visit->encounter_diagnoses,
            'encounter_class' => $visit->encounter_class,
            'encounter_state' => $visit->encounter_state,
            'location' => $visit->location,
            'admit_source' => $visit->admit_source,
            'admit_source_text' => $visit->admit_source_text,
            'discharge_disposition' => $visit->discharge_disposition,
            'discharge_disposition_text' => $visit->discharge_disposition_text,
            'visit_date_source' => $visit->visit_date_source,
            'visit_started_source' => $visit->visit_started_source,
            'visit_started_precision' => $visit->visit_started_precision,
            'visit_started_timezone' => $visit->visit_started_timezone,
            'visit_ended_source' => $visit->visit_ended_source,
            'visit_ended_precision' => $visit->visit_ended_precision,
            'visit_ended_timezone' => $visit->visit_ended_timezone,
            // The care team (#412). A list read eager-loads it; one record reads it here.
            'participants' => $visit->participants
                ->map(fn (PhrOfficeVisitParticipant $participant): array => [
                    'id' => $participant->id,
                    'external_id' => $participant->external_id,
                    'name' => $participant->name,
                    'credentials' => $participant->credentials,
                    'role_code' => $participant->role_code,
                    'role_label' => $participant->role_label,
                    'specialty' => $participant->specialty,
                    'is_primary' => $participant->is_primary,
                    'period_start' => $participant->period_start?->toDateTimeString(),
                    'period_end' => $participant->period_end?->toDateTimeString(),
                ])
                ->values()
                ->all(),
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
            'linked_records' => $this->when(
                $visit->relationLoaded('linkedLabResults'),
                fn (): array => $this->linkedRecords($visit),
            ),
            'medication_transitions' => $this->when(
                $visit->relationLoaded('medicationTransitions'),
                fn (): array => $visit->medicationTransitions->map(fn (PhrMedicationTransition $transition): array => [
                    'id' => $transition->id,
                    'phase' => $transition->phase,
                    'action' => $transition->action,
                    'note' => $transition->note,
                    'review_status' => $transition->review_status,
                    'home_medication' => $this->medicationSummary($transition->homeMedication),
                    'order_medication' => $this->medicationSummary($transition->orderMedication),
                ])->values()->all(),
            ),
            'created_at' => $visit->created_at?->toDateTimeString(),
            'updated_at' => $visit->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * A medication a transition names, as the visit page lists it: enough of its regimen to
     * show what a continued or changed order kept or changed (#439).
     *
     * @return array{id: int, name: string, dose: string|null, dose_unit: string|null, route: string|null, frequency: string|null, as_needed: bool|null, as_needed_reason: string|null, rate: string|null, category: string|null}|null
     */
    private function medicationSummary(?PhrMedication $medication): ?array
    {
        return $medication === null ? null : [
            'id' => $medication->id,
            'name' => $medication->name,
            'dose' => $medication->dose,
            'dose_unit' => $medication->dose_unit,
            'route' => $medication->route,
            'frequency' => $medication->frequency,
            'as_needed' => $medication->as_needed,
            'as_needed_reason' => $medication->as_needed_reason,
            'rate' => $medication->rate,
            'category' => $medication->category,
        ];
    }

    /**
     * A compact summary of each linked record, enough for the visit page to list them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function linkedRecords(PhrOfficeVisit $visit): array
    {
        $summaries = static fn (string $relation, callable $summary): array => $visit->relationLoaded($relation)
            ? $visit->getRelation($relation)->map($summary)->values()->all()
            : [];

        return [
            'documents' => $summaries('linkedDocuments', static fn (PhrDocument $document): array => [
                'id' => $document->id,
                'title' => $document->title ?? $document->original_filename,
                'document_type' => $document->document_type,
                'observed_at' => $document->observed_at?->toDateTimeString(),
            ]),
            'lab_results' => $summaries('linkedLabResults', static fn (PhrLabResult $lab): array => [
                'id' => $lab->id,
                'name' => $lab->analyte ?? $lab->test_name,
                'value' => $lab->value,
                'unit' => $lab->unit,
                'abnormal_flag' => $lab->abnormal_flag,
                'observed_at' => ($lab->collection_datetime ?? $lab->result_datetime)?->toDateTimeString(),
                'review_status' => $lab->review_status,
            ]),
            'vitals' => $summaries('linkedVitals', static fn (PhrPatientVital $vital): array => [
                'id' => $vital->id,
                'name' => $vital->vital_name,
                'value' => $vital->vital_value,
                'unit' => $vital->unit,
                'observed_at' => $vital->observed_at?->toDateTimeString() ?? $vital->vital_date?->toDateString(),
                'review_status' => $vital->review_status,
            ]),
            'medications' => $summaries('linkedMedications', static fn (PhrMedication $medication): array => [
                'id' => $medication->id,
                'name' => $medication->name,
                'dose' => $medication->dose,
                'status' => $medication->status,
                'review_status' => $medication->review_status,
            ]),
            'procedures' => $summaries('linkedProcedures', static fn (PhrProcedure $procedure): array => [
                'id' => $procedure->id,
                'name' => $procedure->name,
                'performed_on' => ($procedure->performed_at ?? $procedure->performed_on)?->toDateString(),
                'status' => $procedure->status,
                'review_status' => $procedure->review_status,
            ]),
        ];
    }
}
