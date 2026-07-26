<?php

namespace App\Services\PHR\Import;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
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
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PhrRecordAttributeMapper
{
    public const array JOB_TYPES = [
        'phr_lab_result',
        'phr_vital',
        'phr_office_visit',
        'phr_medication',
        'phr_immunization',
        'phr_problem_list',
        'phr_procedure',
        'phr_allergy',
        'phr_portal_message',
        'phr_negative_assertion',
        'phr_document',
    ];

    public static function isPhrJobType(string $jobType): bool
    {
        return in_array($jobType, self::JOB_TYPES, true);
    }

    /**
     * @return array<int, string>
     */
    public static function writableJobTypes(): array
    {
        return self::JOB_TYPES;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<int, mixed>
     */
    public function recordsFromPayload(string $jobType, array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        $keys = match ($jobType) {
            'phr_lab_result' => ['lab_results', 'labs', 'results', 'records'],
            'phr_vital' => ['vitals', 'vital_signs', 'records'],
            'phr_office_visit' => ['office_visits', 'visits', 'encounters', 'records'],
            'phr_medication' => ['medications', 'medication_list', 'records'],
            'phr_immunization' => ['immunizations', 'vaccines', 'records'],
            'phr_problem_list' => ['conditions', 'problems', 'problem_list', 'records'],
            'phr_procedure' => ['procedures', 'records'],
            'phr_allergy' => ['allergies', 'allergy_intolerances', 'records'],
            'phr_portal_message' => ['portal_messages', 'messages', 'records'],
            'phr_negative_assertion' => ['negative_assertions', 'negated_facts', 'records'],
            default => ['records'],
        };

        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_is_list($payload[$key]) ? $payload[$key] : [$payload[$key]];
            }
        }

        return [$payload];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array{import_source?: string, source?: string, external_id?: string|null, genai_job_id?: int|null, source_document_id?: int|null}  $options
     * @return array<string, mixed>
     */
    public function attributesFor(PhrPatient $patient, int $actorUserId, string $jobType, array $record, array $options): array
    {
        $base = [
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => $options['import_source'] ?? ValueCoercion::string($record['import_source'] ?? null),
            'external_id' => ValueCoercion::externalId($record, $options),
            // Only link to a source document the caller has resolved for this
            // patient. A record-supplied id is untrusted parsed input and could
            // point at another patient's document, so it is intentionally ignored.
            'source_document_id' => $options['source_document_id'] ?? null,
        ];

        return match ($jobType) {
            'phr_lab_result' => [
                ...$base,
                'test_name' => ValueCoercion::string($record['test_name'] ?? $record['panel'] ?? null),
                'collection_datetime' => ValueCoercion::dateTime($record['collection_datetime'] ?? $record['collected_at'] ?? $record['observed_at'] ?? null),
                'result_datetime' => ValueCoercion::dateTime($record['result_datetime'] ?? $record['resulted_at'] ?? $record['observed_at'] ?? null),
                'result_status' => ValueCoercion::string($record['result_status'] ?? $record['status'] ?? null),
                'ordering_provider' => ValueCoercion::string($record['ordering_provider'] ?? null),
                'resulting_lab' => ValueCoercion::string($record['resulting_lab'] ?? $record['lab'] ?? null),
                'analyte' => ValueCoercion::requiredString($record['analyte'] ?? $record['name'] ?? $record['test_name'] ?? null),
                'value' => ValueCoercion::string($record['value'] ?? $record['raw_value'] ?? $record['result'] ?? null),
                'value_numeric' => ValueCoercion::numeric($record['value_numeric'] ?? $record['numeric_value'] ?? $record['value'] ?? null),
                'unit' => ValueCoercion::string($record['unit'] ?? null),
                'range_min' => ValueCoercion::numeric($record['range_min'] ?? $record['reference_range_low'] ?? null),
                'range_max' => ValueCoercion::numeric($record['range_max'] ?? $record['reference_range_high'] ?? null),
                'range_unit' => ValueCoercion::string($record['range_unit'] ?? $record['unit'] ?? null),
                'reference_range_text' => ValueCoercion::string($record['reference_range_text'] ?? $record['reference_range'] ?? null),
                'normal_value' => ValueCoercion::string($record['normal_value'] ?? null),
                'abnormal_flag' => ValueCoercion::string($record['abnormal_flag'] ?? $record['flag'] ?? null),
                'source' => ValueCoercion::string($record['source'] ?? null) ?? ($options['source'] ?? null),
                'notes' => ValueCoercion::string($record['notes'] ?? null),
            ],
            'phr_vital' => [
                ...$base,
                'vital_name' => ValueCoercion::requiredString($record['vital_name'] ?? $record['name'] ?? null),
                'vital_date' => ValueCoercion::date($record['vital_date'] ?? $record['date'] ?? $record['observed_at'] ?? null),
                'observed_at' => ValueCoercion::dateTime($record['observed_at'] ?? $record['date_time'] ?? null),
                'vital_value' => ValueCoercion::string($record['vital_value'] ?? $record['value'] ?? $record['raw_value'] ?? null),
                'value_numeric' => ValueCoercion::numeric($record['value_numeric'] ?? $record['value'] ?? null),
                'value_numeric_secondary' => ValueCoercion::numeric($record['value_numeric_secondary'] ?? $record['secondary_value'] ?? null),
                'unit' => ValueCoercion::string($record['unit'] ?? null),
                'secondary_unit' => ValueCoercion::string($record['secondary_unit'] ?? null),
                'body_site' => ValueCoercion::string($record['body_site'] ?? null),
                'source' => ValueCoercion::string($record['source'] ?? null) ?? ($options['source'] ?? null),
                'notes' => ValueCoercion::string($record['notes'] ?? null),
            ],
            'phr_office_visit' => [
                ...$base,
                'visit_date' => ValueCoercion::date($record['visit_date'] ?? $record['date'] ?? $record['visit_started_at'] ?? null),
                'visit_started_at' => ValueCoercion::dateTime($record['visit_started_at'] ?? $record['started_at'] ?? null),
                'visit_ended_at' => ValueCoercion::dateTime($record['visit_ended_at'] ?? $record['ended_at'] ?? null),
                'visit_type' => ValueCoercion::string($record['visit_type'] ?? $record['type'] ?? null),
                'provider_name' => ValueCoercion::string($record['provider_name'] ?? $record['provider'] ?? null),
                'provider_specialty' => ValueCoercion::string($record['provider_specialty'] ?? null),
                'facility_name' => ValueCoercion::string($record['facility_name'] ?? $record['facility'] ?? null),
                'chief_complaint' => ValueCoercion::string($record['chief_complaint'] ?? $record['reason'] ?? null),
                'assessment' => ValueCoercion::string($record['assessment'] ?? null),
                'plan' => ValueCoercion::string($record['plan'] ?? null),
                'subjective' => ValueCoercion::string($record['subjective'] ?? null),
                'objective' => ValueCoercion::string($record['objective'] ?? null),
                'icd10_codes' => ValueCoercion::codes($record['icd10_codes'] ?? $record['diagnoses'] ?? null),
                'cpt_codes' => ValueCoercion::codes($record['cpt_codes'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? null),
            ],
            'phr_medication' => [
                ...$base,
                'name' => ValueCoercion::requiredString($record['name'] ?? $record['medication'] ?? null),
                'rxnorm_code' => ValueCoercion::string($record['rxnorm_code'] ?? null),
                'dose' => ValueCoercion::string($record['dose'] ?? null),
                'dose_unit' => ValueCoercion::string($record['dose_unit'] ?? null),
                'route' => ValueCoercion::string($record['route'] ?? null),
                'frequency' => ValueCoercion::string($record['frequency'] ?? null),
                'started_on' => ValueCoercion::date($record['started_on'] ?? $record['start_date'] ?? null),
                'ended_on' => ValueCoercion::date($record['ended_on'] ?? $record['end_date'] ?? null),
                'status' => ValueCoercion::string($record['status'] ?? null) ?? 'active',
                'prescriber_name' => ValueCoercion::string($record['prescriber_name'] ?? $record['prescriber'] ?? null),
                'reason_for_use' => ValueCoercion::string($record['reason_for_use'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? null),
            ],
            'phr_immunization' => [
                ...$base,
                'vaccine_name' => ValueCoercion::requiredString($record['vaccine_name'] ?? $record['name'] ?? $record['vaccine'] ?? null),
                'cvx_code' => ValueCoercion::string($record['cvx_code'] ?? null),
                'manufacturer' => ValueCoercion::string($record['manufacturer'] ?? null),
                'lot_number' => ValueCoercion::string($record['lot_number'] ?? null),
                'administered_on' => ValueCoercion::date($record['administered_on'] ?? $record['date'] ?? null),
                'dose_number' => ValueCoercion::integer($record['dose_number'] ?? null),
                'series_doses' => ValueCoercion::integer($record['series_doses'] ?? null),
                'site' => ValueCoercion::string($record['site'] ?? null),
                'route' => ValueCoercion::string($record['route'] ?? null),
                'administered_by' => ValueCoercion::string($record['administered_by'] ?? null),
                'facility_name' => ValueCoercion::string($record['facility_name'] ?? null),
                'notes' => ValueCoercion::string($record['notes'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? null),
            ],
            'phr_problem_list' => [
                ...$base,
                'name' => ValueCoercion::requiredString($record['name'] ?? $record['condition'] ?? $record['problem'] ?? null),
                'icd10_code' => ValueCoercion::string($record['icd10_code'] ?? null),
                'snomed_code' => ValueCoercion::string($record['snomed_code'] ?? null),
                'onset_date' => ValueCoercion::date($record['onset_date'] ?? null),
                'abated_date' => ValueCoercion::date($record['abated_date'] ?? $record['resolved_date'] ?? null),
                'clinical_status' => ValueCoercion::string($record['clinical_status'] ?? $record['status'] ?? null) ?? 'active',
                'verification_status' => ValueCoercion::string($record['verification_status'] ?? null) ?? 'confirmed',
                'severity' => ValueCoercion::string($record['severity'] ?? null),
                'notes' => ValueCoercion::string($record['notes'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? null),
            ],
            'phr_procedure' => [
                ...$base,
                'name' => ValueCoercion::requiredString($record['name'] ?? $record['procedure'] ?? null),
                'cpt_code' => ValueCoercion::string($record['cpt_code'] ?? null),
                'snomed_code' => ValueCoercion::string($record['snomed_code'] ?? null),
                'performed_at' => ValueCoercion::dateTime($record['performed_at'] ?? null),
                'performed_on' => ValueCoercion::date($record['performed_on'] ?? $record['date'] ?? $record['performed_at'] ?? null),
                'performer_name' => ValueCoercion::string($record['performer_name'] ?? null),
                'performer_specialty' => ValueCoercion::string($record['performer_specialty'] ?? null),
                'facility_name' => ValueCoercion::string($record['facility_name'] ?? null),
                'status' => ValueCoercion::string($record['status'] ?? null) ?? 'completed',
                'reason' => ValueCoercion::string($record['reason'] ?? null),
                'outcome' => ValueCoercion::string($record['outcome'] ?? null),
                'notes' => ValueCoercion::string($record['notes'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? null),
            ],
            'phr_allergy' => [
                ...$base,
                'substance' => ValueCoercion::requiredString($record['substance'] ?? $record['allergen'] ?? $record['name'] ?? null),
                'rxnorm_code' => ValueCoercion::string($record['rxnorm_code'] ?? null),
                'snomed_code' => ValueCoercion::string($record['snomed_code'] ?? null),
                'category' => ValueCoercion::string($record['category'] ?? null),
                'criticality' => ValueCoercion::string($record['criticality'] ?? null),
                'clinical_status' => ValueCoercion::string($record['clinical_status'] ?? null) ?? 'active',
                'verification_status' => ValueCoercion::string($record['verification_status'] ?? null) ?? 'confirmed',
                'reaction' => ValueCoercion::string($record['reaction'] ?? null),
                'severity' => ValueCoercion::string($record['severity'] ?? null),
                'notes' => ValueCoercion::string($record['notes'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? null),
            ],
            'phr_portal_message' => [
                ...$base,
                'message_at' => ValueCoercion::dateTime($record['message_at'] ?? $record['sent_at'] ?? $record['created_at'] ?? $record['date'] ?? null),
                'direction' => ValueCoercion::string($record['direction'] ?? null),
                'subject' => ValueCoercion::string($record['subject'] ?? $record['title'] ?? null),
                'sender_name' => ValueCoercion::string($record['sender_name'] ?? $record['sender'] ?? $record['from'] ?? null),
                'recipient_name' => ValueCoercion::string($record['recipient_name'] ?? $record['recipient'] ?? $record['to'] ?? null),
                'summary' => ValueCoercion::string($record['summary'] ?? null),
                'clinical_relevance' => ValueCoercion::string($record['clinical_relevance'] ?? $record['relevance'] ?? null),
                'source_refs' => ValueCoercion::stringList($record['source_refs'] ?? $record['source_references'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? $record['text'] ?? null),
            ],
            'phr_negative_assertion' => [
                ...$base,
                'assertion_type' => ValueCoercion::string($record['assertion_type'] ?? $record['type'] ?? null) ?? 'negative_assertion',
                'statement' => ValueCoercion::requiredString($record['statement'] ?? $record['description'] ?? $record['raw_text'] ?? null),
                'scope' => ValueCoercion::string($record['scope'] ?? null),
                'observed_on' => ValueCoercion::date($record['observed_on'] ?? $record['date'] ?? null),
                'notes' => ValueCoercion::string($record['notes'] ?? null),
                'source_refs' => ValueCoercion::stringList($record['source_refs'] ?? $record['source_references'] ?? null),
                'raw_text' => ValueCoercion::string($record['raw_text'] ?? $record['text'] ?? null),
            ],
            default => [],
        };
    }

    /**
     * @return class-string<Model>
     */
    public function modelClassFor(string $jobType): string
    {
        return match ($jobType) {
            'phr_lab_result' => PhrLabResult::class,
            'phr_vital' => PhrPatientVital::class,
            'phr_office_visit' => PhrOfficeVisit::class,
            'phr_medication' => PhrMedication::class,
            'phr_immunization' => PhrImmunization::class,
            'phr_problem_list' => PhrCondition::class,
            'phr_procedure' => PhrProcedure::class,
            'phr_allergy' => PhrAllergy::class,
            'phr_portal_message' => PhrPortalMessage::class,
            'phr_negative_assertion' => PhrNegativeAssertion::class,
            'phr_document' => PhrDocument::class,
            default => throw new InvalidArgumentException("Unsupported PHR job type: {$jobType}"),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function missingRequiredField(string $jobType, array $attributes): bool
    {
        $requiredField = match ($jobType) {
            'phr_lab_result' => 'analyte',
            'phr_vital' => 'vital_name',
            'phr_medication', 'phr_problem_list', 'phr_procedure' => 'name',
            'phr_immunization' => 'vaccine_name',
            'phr_allergy' => 'substance',
            'phr_negative_assertion' => 'statement',
            default => null,
        };

        if ($jobType === 'phr_portal_message') {
            return empty($attributes['summary']) && empty($attributes['subject']) && empty($attributes['raw_text']);
        }

        return $requiredField !== null && empty($attributes[$requiredField]);
    }
}
