<?php

namespace App\GenAiProcessor\Services;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\Prompts\Phr\PhrPromptTemplate;
use App\Models\PhrDocument;

final class PhrGenAiRequestPreparationService
{
    public const string SUBMISSION_TOOL = 'submit_phr_import';

    public function prepare(GenAiImportJob $job): PreparedPhrGenAiRequest
    {
        return new PreparedPhrGenAiRequest(
            prompt: (new PhrPromptTemplate($job->job_type))->build($job->getContextArray()),
            submissionSchema: $this->schema($job->job_type),
        );
    }

    /** @return array<string, mixed> */
    private function schema(string $jobType): array
    {
        if ($jobType === 'phr_document') {
            return $this->documentBundleSchema();
        }

        $record = $this->recordSchema($jobType);

        return $this->object([
            'records' => [
                'type' => 'array',
                'maxItems' => 500,
                'items' => $record,
            ],
        ], ['records']);
    }

    /** @return array<string, mixed> */
    private function documentBundleSchema(): array
    {
        $records = [];
        foreach ([
            'conditions' => 'phr_problem_list',
            'allergies' => 'phr_allergy',
            'immunizations' => 'phr_immunization',
            'medications' => 'phr_medication',
            'vitals' => 'phr_vital',
            'lab_results' => 'phr_lab_result',
            'procedures' => 'phr_procedure',
            'encounters' => 'phr_office_visit',
            'portal_messages' => 'phr_portal_message',
            'negative_assertions' => 'phr_negative_assertion',
        ] as $name => $jobType) {
            $records[$name] = ['type' => 'array', 'maxItems' => 500, 'items' => $this->recordSchema($jobType)];
        }

        return $this->object([
            'schema_version' => ['const' => 'phr_pdf_bundle.v1'],
            'source_document' => $this->object([
                'record_key' => $this->nullableString(255),
                'title' => $this->nullableString(500),
                'document_type' => [
                    'type' => ['string', 'null'],
                    'enum' => [...PhrDocument::DOCUMENT_TYPES, null],
                ],
                'observed_at' => $this->nullableString(32),
                'summary' => $this->nullableString(20_000),
                'extracted_text' => $this->nullableString(500_000),
                'tags' => $this->stringList(100, 100),
            ]),
            'patient_context' => $this->object([
                'display_name' => $this->nullableString(500),
                'birth_date' => $this->nullableString(32),
                'sex_at_birth' => $this->nullableString(100),
            ]),
            'records' => $this->object($records, array_keys($records)),
        ], ['schema_version', 'source_document', 'records']);
    }

    /** @return array<string, mixed> */
    private function recordSchema(string $jobType): array
    {
        $common = [
            'record_key' => $this->nullableString(255),
            'source_refs' => $this->stringList(100, 500),
            'raw_text' => $this->nullableString(100_000),
            'notes' => $this->nullableString(20_000),
        ];
        [$fields, $required] = match ($jobType) {
            'phr_lab_result' => [[
                'test_name' => $this->nullableString(), 'analyte' => $this->nullableString(),
                'value' => $this->nullableString(), 'value_numeric' => $this->nullableNumber(),
                'unit' => $this->nullableString(), 'range_min' => $this->nullableNumber(),
                'range_max' => $this->nullableNumber(), 'reference_range_text' => $this->nullableString(),
                'abnormal_flag' => $this->nullableString(50), 'collection_datetime' => $this->nullableString(32),
                'observed_at' => $this->nullableString(32), 'result_datetime' => $this->nullableString(32),
                'ordering_provider' => $this->nullableString(), 'resulting_lab' => $this->nullableString(),
            ], ['analyte']],
            'phr_vital' => [[
                'vital_name' => $this->nullableString(), 'vital_value' => $this->nullableString(),
                'value_numeric' => $this->nullableNumber(), 'value_numeric_secondary' => $this->nullableNumber(),
                'unit' => $this->nullableString(), 'secondary_unit' => $this->nullableString(),
                'vital_date' => $this->nullableString(32), 'observed_at' => $this->nullableString(32),
                'body_site' => $this->nullableString(),
            ], ['vital_name']],
            'phr_office_visit' => [[
                'visit_date' => $this->nullableString(32), 'visit_started_at' => $this->nullableString(32),
                'visit_type' => $this->nullableString(), 'provider_name' => $this->nullableString(),
                'provider_specialty' => $this->nullableString(), 'facility_name' => $this->nullableString(),
                'chief_complaint' => $this->nullableString(10_000), 'assessment' => $this->nullableString(20_000),
                'plan' => $this->nullableString(20_000), 'subjective' => $this->nullableString(20_000),
                'objective' => $this->nullableString(20_000), 'icd10_codes' => $this->codeList(),
                'cpt_codes' => $this->codeList(),
            ], []],
            'phr_medication' => [[
                'name' => $this->nullableString(), 'rxnorm_code' => $this->nullableString(),
                'dose' => $this->nullableString(), 'dose_unit' => $this->nullableString(),
                'route' => $this->nullableString(), 'frequency' => $this->nullableString(),
                'started_on' => $this->nullableString(32), 'ended_on' => $this->nullableString(32),
                'status' => $this->nullableString(), 'prescriber_name' => $this->nullableString(),
                'reason_for_use' => $this->nullableString(),
            ], ['name']],
            'phr_immunization' => [[
                'vaccine_name' => $this->nullableString(), 'cvx_code' => $this->nullableString(),
                'manufacturer' => $this->nullableString(), 'lot_number' => $this->nullableString(),
                'administered_on' => $this->nullableString(32), 'dose_number' => $this->nullableInteger(),
                'series_doses' => $this->nullableInteger(), 'site' => $this->nullableString(),
                'route' => $this->nullableString(), 'administered_by' => $this->nullableString(),
                'facility_name' => $this->nullableString(),
            ], ['vaccine_name']],
            'phr_problem_list' => [[
                'name' => $this->nullableString(), 'icd10_code' => $this->nullableString(),
                'snomed_code' => $this->nullableString(), 'onset_date' => $this->nullableString(32),
                'abated_date' => $this->nullableString(32), 'clinical_status' => $this->nullableString(),
                'verification_status' => $this->nullableString(), 'severity' => $this->nullableString(),
            ], ['name']],
            'phr_procedure' => [[
                'name' => $this->nullableString(), 'cpt_code' => $this->nullableString(),
                'snomed_code' => $this->nullableString(), 'performed_at' => $this->nullableString(32),
                'performed_on' => $this->nullableString(32), 'performer_name' => $this->nullableString(),
                'performer_specialty' => $this->nullableString(), 'facility_name' => $this->nullableString(),
                'status' => $this->nullableString(), 'reason' => $this->nullableString(),
                'outcome' => $this->nullableString(),
            ], ['name']],
            'phr_allergy' => [[
                'substance' => $this->nullableString(), 'rxnorm_code' => $this->nullableString(),
                'snomed_code' => $this->nullableString(), 'category' => $this->nullableString(),
                'criticality' => $this->nullableString(), 'clinical_status' => $this->nullableString(),
                'verification_status' => $this->nullableString(), 'reaction' => $this->nullableString(),
                'severity' => $this->nullableString(),
            ], ['substance']],
            'phr_portal_message' => [[
                'message_at' => $this->nullableString(32), 'direction' => $this->nullableString(),
                'subject' => $this->nullableString(), 'sender_name' => $this->nullableString(),
                'recipient_name' => $this->nullableString(), 'summary' => $this->nullableString(20_000),
                'clinical_relevance' => $this->nullableString(20_000),
            ], []],
            'phr_negative_assertion' => [[
                'assertion_type' => $this->nullableString(), 'statement' => $this->nullableString(20_000),
                'scope' => $this->nullableString(), 'observed_on' => $this->nullableString(32),
            ], ['statement']],
            default => throw new \InvalidArgumentException("Unsupported PHR job type [{$jobType}]."),
        };

        foreach ($required as $field) {
            $fields[$field] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 10_000];
        }

        $schema = $this->object([...$common, ...$fields], $required);
        if ($jobType === 'phr_portal_message') {
            $schema['anyOf'] = [
                ['required' => ['subject'], 'properties' => ['subject' => ['type' => 'string', 'minLength' => 1]]],
                ['required' => ['summary'], 'properties' => ['summary' => ['type' => 'string', 'minLength' => 1]]],
                ['required' => ['raw_text'], 'properties' => ['raw_text' => ['type' => 'string', 'minLength' => 1]]],
            ];
        }

        return $schema;
    }

    /** @param array<string, mixed> $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function nullableString(int $maxLength = 10_000): array
    {
        return ['type' => ['string', 'null'], 'maxLength' => $maxLength];
    }

    /** @return array<string, mixed> */
    private function nullableNumber(): array
    {
        return ['type' => ['number', 'null']];
    }

    /** @return array<string, mixed> */
    private function nullableInteger(): array
    {
        return ['type' => ['integer', 'null']];
    }

    /** @return array<string, mixed> */
    private function stringList(int $maxItems, int $maxLength): array
    {
        return ['type' => ['array', 'null'], 'maxItems' => $maxItems, 'items' => ['type' => 'string', 'maxLength' => $maxLength]];
    }

    /** @return array<string, mixed> */
    private function codeList(): array
    {
        return ['type' => ['array', 'null'], 'maxItems' => 100, 'items' => $this->object([
            'code' => $this->nullableString(100),
            'description' => $this->nullableString(1_000),
        ])];
    }
}
