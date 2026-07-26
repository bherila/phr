<?php

namespace App\Services\PHR\Export;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDicomStudy;
use App\Models\PhrDocument;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrNegativeAssertion;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\PhrPortalMessage;
use App\Models\PhrProcedure;
use Illuminate\Support\Carbon;

class PhrFhirExporter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function bundleJson(array $data): string
    {
        /** @var PhrPatient $patient */
        $patient = $data['patient'];
        $entries = [$this->entry($this->patientResource($patient))];

        foreach ($data['lab_results'] as $lab) {
            $entries[] = $this->entry($this->labObservationResource($lab));
        }
        foreach ($data['vitals'] as $vital) {
            $entries[] = $this->entry($this->vitalObservationResource($vital));
        }
        foreach ($data['conditions'] as $condition) {
            $entries[] = $this->entry($this->conditionResource($condition));
        }
        foreach ($data['medications'] as $medication) {
            $entries[] = $this->entry($this->medicationResource($medication));
        }
        foreach ($data['procedures'] as $procedure) {
            $entries[] = $this->entry($this->procedureResource($procedure));
        }
        foreach ($data['immunizations'] as $immunization) {
            $entries[] = $this->entry($this->immunizationResource($immunization));
        }
        foreach ($data['allergies'] as $allergy) {
            $entries[] = $this->entry($this->allergyResource($allergy));
        }
        foreach ($data['office_visits'] as $visit) {
            $entries[] = $this->entry($this->encounterResource($visit));
            $entries[] = $this->entry($this->clinicalImpressionResource($visit));
        }
        foreach ($data['portal_messages'] as $message) {
            $entries[] = $this->entry($this->portalMessageResource($message));
        }
        foreach ($data['negative_assertions'] as $assertion) {
            $entries[] = $this->entry($this->negativeAssertionResource($assertion));
        }
        foreach ($data['dicom_studies'] as $study) {
            $entries[] = $this->entry($this->imagingStudyResource($study));
        }
        foreach ($data['documents'] as $document) {
            $entries[] = $this->entry($this->documentReferenceResource($document));
        }

        return json_encode([
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'timestamp' => now()->toIso8601String(),
            'entry' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array{fullUrl: string, resource: array<string, mixed>}
     */
    private function entry(array $resource): array
    {
        return [
            'fullUrl' => 'urn:uuid:'.$resource['resourceType'].'-'.$resource['id'],
            'resource' => $this->stripNulls($resource),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function patientResource(PhrPatient $patient): array
    {
        return [
            'resourceType' => 'Patient',
            'id' => 'phr-patient-'.$patient->id,
            'name' => [['text' => $patient->display_name ?? 'Patient '.$patient->id]],
            'birthDate' => $patient->birth_date?->toDateString(),
            'gender' => $this->fhirGender($patient->sex_at_birth),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function labObservationResource(PhrLabResult $lab): array
    {
        return [
            'resourceType' => 'Observation',
            'id' => 'phr-lab-'.$lab->id,
            'status' => $lab->result_status ?: 'final',
            'category' => [$this->coding('http://terminology.hl7.org/CodeSystem/observation-category', 'laboratory', 'Laboratory')],
            'code' => $this->textCode($lab->analyte ?? $lab->test_name ?? 'Lab result'),
            'subject' => $this->patientReference($lab->patient_id),
            'effectiveDateTime' => $this->fhirDateTime($lab->result_datetime ?? $lab->collection_datetime),
            'valueQuantity' => $lab->value_numeric !== null ? [
                'value' => (float) $lab->value_numeric,
                'unit' => $lab->unit,
            ] : null,
            'valueString' => $lab->value_numeric === null ? $lab->value : null,
            'interpretation' => $lab->abnormal_flag ? [$this->textCode($lab->abnormal_flag)] : null,
            'referenceRange' => ($lab->range_min !== null || $lab->range_max !== null || $lab->reference_range_text !== null) ? [[
                'low' => $lab->range_min !== null ? ['value' => (float) $lab->range_min, 'unit' => $lab->range_unit ?? $lab->unit] : null,
                'high' => $lab->range_max !== null ? ['value' => (float) $lab->range_max, 'unit' => $lab->range_unit ?? $lab->unit] : null,
                'text' => $lab->reference_range_text,
            ]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vitalObservationResource(PhrPatientVital $vital): array
    {
        return [
            'resourceType' => 'Observation',
            'id' => 'phr-vital-'.$vital->id,
            'status' => 'final',
            'category' => [$this->coding('http://terminology.hl7.org/CodeSystem/observation-category', 'vital-signs', 'Vital Signs')],
            'code' => $this->textCode($vital->vital_name ?? 'Vital sign'),
            'subject' => $this->patientReference($vital->patient_id),
            'effectiveDateTime' => $this->fhirDateTime($vital->observed_at ?? $vital->vital_date),
            'valueQuantity' => $vital->value_numeric !== null ? [
                'value' => (float) $vital->value_numeric,
                'unit' => $vital->unit,
            ] : null,
            'valueString' => $vital->value_numeric === null ? $vital->vital_value : null,
            'component' => $vital->value_numeric_secondary !== null ? [[
                'code' => $this->textCode(($vital->vital_name ?? 'Vital sign').' secondary'),
                'valueQuantity' => [
                    'value' => (float) $vital->value_numeric_secondary,
                    'unit' => $vital->secondary_unit,
                ],
            ]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionResource(PhrCondition $condition): array
    {
        return [
            'resourceType' => 'Condition',
            'id' => 'phr-condition-'.$condition->id,
            'subject' => $this->patientReference($condition->patient_id),
            'code' => $this->textCode($condition->name, $condition->icd10_code, 'http://hl7.org/fhir/sid/icd-10-cm'),
            'clinicalStatus' => $this->textCode($condition->clinical_status),
            'verificationStatus' => $this->textCode($condition->verification_status),
            'onsetDateTime' => $this->fhirDate($condition->onset_date),
            'abatementDateTime' => $this->fhirDate($condition->abated_date),
            'note' => $condition->notes ? [['text' => $condition->notes]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function medicationResource(PhrMedication $medication): array
    {
        return [
            'resourceType' => 'MedicationStatement',
            'id' => 'phr-medication-'.$medication->id,
            'subject' => $this->patientReference($medication->patient_id),
            'status' => $medication->status,
            'medicationCodeableConcept' => $this->textCode($medication->name, $medication->rxnorm_code, 'http://www.nlm.nih.gov/research/umls/rxnorm'),
            'effectivePeriod' => [
                'start' => $this->fhirDate($medication->started_on),
                'end' => $this->fhirDate($medication->ended_on),
            ],
            'dosage' => $medication->dose || $medication->frequency ? [[
                'text' => trim(implode(' ', array_filter([$medication->dose, $medication->dose_unit, $medication->route, $medication->frequency]))),
            ]] : null,
            'reasonCode' => $medication->reason_for_use ? [$this->textCode($medication->reason_for_use)] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function procedureResource(PhrProcedure $procedure): array
    {
        return [
            'resourceType' => 'Procedure',
            'id' => 'phr-procedure-'.$procedure->id,
            'subject' => $this->patientReference($procedure->patient_id),
            'status' => $procedure->status,
            'code' => $this->textCode($procedure->name, $procedure->cpt_code, 'http://www.ama-assn.org/go/cpt'),
            'performedDateTime' => $this->fhirDateTime($procedure->performed_at ?? $procedure->performed_on),
            'performer' => $procedure->performer_name ? [['actor' => ['display' => $procedure->performer_name]]] : null,
            'location' => $procedure->facility_name ? ['display' => $procedure->facility_name] : null,
            'reasonCode' => $procedure->reason ? [$this->textCode($procedure->reason)] : null,
            'outcome' => $procedure->outcome ? $this->textCode($procedure->outcome) : null,
            'note' => $procedure->notes ? [['text' => $procedure->notes]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function immunizationResource(PhrImmunization $immunization): array
    {
        return [
            'resourceType' => 'Immunization',
            'id' => 'phr-immunization-'.$immunization->id,
            'status' => 'completed',
            'patient' => $this->patientReference($immunization->patient_id),
            'vaccineCode' => $this->textCode($immunization->vaccine_name, $immunization->cvx_code, 'http://hl7.org/fhir/sid/cvx'),
            'occurrenceDateTime' => $this->fhirDate($immunization->administered_on),
            'manufacturer' => $immunization->manufacturer ? ['display' => $immunization->manufacturer] : null,
            'lotNumber' => $immunization->lot_number,
            'site' => $immunization->site ? $this->textCode($immunization->site) : null,
            'route' => $immunization->route ? $this->textCode($immunization->route) : null,
            'performer' => $immunization->administered_by ? [['actor' => ['display' => $immunization->administered_by]]] : null,
            'note' => $immunization->notes ? [['text' => $immunization->notes]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allergyResource(PhrAllergy $allergy): array
    {
        return [
            'resourceType' => 'AllergyIntolerance',
            'id' => 'phr-allergy-'.$allergy->id,
            'patient' => $this->patientReference($allergy->patient_id),
            'code' => $this->textCode($allergy->substance, $allergy->rxnorm_code, 'http://www.nlm.nih.gov/research/umls/rxnorm'),
            'category' => $allergy->category ? [$allergy->category] : null,
            'criticality' => $allergy->criticality,
            'clinicalStatus' => $this->textCode($allergy->clinical_status),
            'verificationStatus' => $this->textCode($allergy->verification_status),
            'reaction' => $allergy->reaction ? [[
                'manifestation' => [$this->textCode($allergy->reaction)],
                'severity' => $allergy->severity,
            ]] : null,
            'note' => $allergy->notes ? [['text' => $allergy->notes]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterResource(PhrOfficeVisit $visit): array
    {
        return [
            'resourceType' => 'Encounter',
            'id' => 'phr-visit-'.$visit->id,
            'status' => 'finished',
            'subject' => $this->patientReference($visit->patient_id),
            'type' => $visit->visit_type ? [$this->textCode($visit->visit_type)] : null,
            'period' => [
                'start' => $this->fhirDateTime($visit->visit_started_at ?? $visit->visit_date),
                'end' => $this->fhirDateTime($visit->visit_ended_at),
            ],
            'participant' => $visit->provider_name ? [['individual' => ['display' => $visit->provider_name]]] : null,
            'reasonCode' => $visit->chief_complaint ? [$this->textCode($visit->chief_complaint)] : null,
            'serviceProvider' => $visit->facility_name ? ['display' => $visit->facility_name] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clinicalImpressionResource(PhrOfficeVisit $visit): array
    {
        return [
            'resourceType' => 'ClinicalImpression',
            'id' => 'phr-visit-impression-'.$visit->id,
            'status' => 'completed',
            'subject' => $this->patientReference($visit->patient_id),
            'encounter' => ['reference' => 'Encounter/phr-visit-'.$visit->id],
            'date' => $this->fhirDateTime($visit->visit_started_at ?? $visit->visit_date),
            'description' => $visit->chief_complaint,
            'summary' => trim(implode("\n\n", array_filter([$visit->assessment, $visit->plan]))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function portalMessageResource(PhrPortalMessage $message): array
    {
        return [
            'resourceType' => 'Communication',
            'id' => 'phr-portal-message-'.$message->id,
            'status' => 'completed',
            'category' => [$this->textCode('Patient portal message')],
            'subject' => $this->patientReference($message->patient_id),
            'sent' => $this->fhirDateTime($message->message_at),
            'sender' => $message->sender_name ? ['display' => $message->sender_name] : null,
            'recipient' => $message->recipient_name ? [['display' => $message->recipient_name]] : null,
            'topic' => $message->subject ? $this->textCode($message->subject) : null,
            'payload' => [[
                'contentString' => $message->summary ?? $message->raw_text ?? $message->subject ?? 'Portal message',
            ]],
            'note' => $this->notes([$message->clinical_relevance, $message->raw_text]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function negativeAssertionResource(PhrNegativeAssertion $assertion): array
    {
        if ($assertion->assertion_type === 'allergy_absence') {
            return [
                'resourceType' => 'AllergyIntolerance',
                'id' => 'phr-negative-assertion-'.$assertion->id,
                'patient' => $this->patientReference($assertion->patient_id),
                'code' => $this->textCode($assertion->statement, '716186003', 'http://snomed.info/sct'),
                'clinicalStatus' => $this->coding('http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical', 'inactive', 'Inactive'),
                'verificationStatus' => $this->coding('http://terminology.hl7.org/CodeSystem/allergyintolerance-verification', 'refuted', 'Refuted'),
                'recordedDate' => $this->fhirDate($assertion->observed_on),
                'note' => $this->notes([$assertion->scope, $assertion->notes, $assertion->raw_text]),
            ];
        }

        return [
            'resourceType' => 'Observation',
            'id' => 'phr-negative-assertion-'.$assertion->id,
            'status' => 'final',
            'category' => [$this->coding('http://terminology.hl7.org/CodeSystem/observation-category', 'survey', 'Survey')],
            'code' => $this->textCode($assertion->scope ?? $assertion->assertion_type),
            'subject' => $this->patientReference($assertion->patient_id),
            'effectiveDateTime' => $this->fhirDate($assertion->observed_on),
            'valueCodeableConcept' => $this->textCode($assertion->statement),
            'interpretation' => [$this->coding('http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation', 'NEG', 'Negative')],
            'note' => $this->notes([$assertion->assertion_type, $assertion->notes, $assertion->raw_text]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imagingStudyResource(PhrDicomStudy $study): array
    {
        return [
            'resourceType' => 'ImagingStudy',
            'id' => 'phr-imaging-'.$study->id,
            'status' => 'available',
            'subject' => $this->patientReference($study->patient_id),
            'started' => $this->fhirDate($study->study_date),
            'identifier' => [['system' => 'urn:dicom:uid', 'value' => $study->study_instance_uid]],
            'description' => $study->description,
            'numberOfSeries' => $study->series_count ?? null,
            'numberOfInstances' => $study->instances_count ?? null,
            'modality' => $study->modalities ? [['code' => $study->modalities]] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentReferenceResource(PhrDocument $document): array
    {
        return [
            'resourceType' => 'DocumentReference',
            'id' => 'phr-document-'.$document->id,
            'status' => 'current',
            'subject' => $this->patientReference($document->patient_id),
            'type' => $this->textCode($document->document_type),
            'description' => $document->summary ?? $document->title,
            'content' => [[
                'attachment' => [
                    'contentType' => $document->mime_type,
                    'title' => $document->original_filename ?? $document->title,
                    'url' => $document->storage_path ? 'documents/'.$document->id.'-'.$this->safeFilename($document->original_filename ?? ('document-'.$document->id)) : null,
                    'size' => $document->byte_size,
                    'hash' => $document->file_hash,
                ],
            ]],
        ];
    }

    /**
     * @return array{reference: string}
     */
    private function patientReference(int $patientId): array
    {
        return ['reference' => 'Patient/phr-patient-'.$patientId];
    }

    /**
     * @return array{text: string, coding?: array<int, array<string, string>>}
     */
    private function textCode(string $text, ?string $code = null, ?string $system = null): array
    {
        $concept = ['text' => $text];
        if ($code !== null && $code !== '') {
            $concept['coding'] = [[
                'system' => $system ?? 'urn:phr:code',
                'code' => $code,
                'display' => $text,
            ]];
        }

        return $concept;
    }

    /**
     * @return array{coding: array<int, array{system: string, code: string, display: string}>}
     */
    private function coding(string $system, string $code, string $display): array
    {
        return ['coding' => [['system' => $system, 'code' => $code, 'display' => $display]]];
    }

    private function fhirGender(?string $sexAtBirth): ?string
    {
        return match (strtolower((string) $sexAtBirth)) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => $sexAtBirth ? 'unknown' : null,
        };
    }

    private function fhirDate(mixed $date): ?string
    {
        if ($date instanceof Carbon) {
            return $date->toDateString();
        }

        return $date ? (string) $date : null;
    }

    private function fhirDateTime(mixed $date): ?string
    {
        if ($date instanceof Carbon) {
            return $date->toIso8601String();
        }

        return $date ? (string) $date : null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, array{text: string}>|null
     */
    private function notes(array $values): ?array
    {
        $notes = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                $notes[] = ['text' => $text];
            }
        }

        return $notes === [] ? null : $notes;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function stripNulls(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            if ($item === null || $item === [] || $item === '') {
                continue;
            }

            $clean[$key] = is_array($item) ? $this->stripNulls($item) : $item;
        }

        return $clean;
    }

    private function safeFilename(string $filename): string
    {
        $safe = preg_replace('/[^\w.\-]+/', '_', $filename) ?: 'document';

        return trim($safe, '_') ?: 'document';
    }
}
