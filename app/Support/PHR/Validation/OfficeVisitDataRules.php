<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;

final class OfficeVisitDataRules implements ClinicalDataRules
{
    public static function rules(bool $partial = false): array
    {
        return [
            'visit_date' => ['nullable', 'date'],
            'visit_started_at' => ['nullable', 'date'],
            'visit_ended_at' => ['nullable', 'date'],
            'visit_type' => ['nullable', 'string', 'max:100'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'provider_specialty' => ['nullable', 'string', 'max:100'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'chief_complaint' => ['nullable', 'string', 'max:10000'],
            'assessment' => ['nullable', 'string', 'max:10000'],
            'plan' => ['nullable', 'string', 'max:10000'],
            'subjective' => ['nullable', 'string', 'max:10000'],
            'objective' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
            'icd10_codes' => ['nullable', 'array', 'max:100'],
            'icd10_codes.*' => ['array:code,description'],
            'icd10_codes.*.code' => ['required', 'string', 'max:20'],
            'icd10_codes.*.description' => ['required', 'string', 'max:255'],
            'cpt_codes' => ['nullable', 'array', 'max:100'],
            'cpt_codes.*' => ['array:code,description'],
            'cpt_codes.*.code' => ['required', 'string', 'max:20'],
            'cpt_codes.*.description' => ['required', 'string', 'max:255'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'visit_date' => ClinicalJsonSchema::nullableString('date'),
            'visit_started_at' => ClinicalJsonSchema::nullableString('date-time'),
            'visit_ended_at' => ClinicalJsonSchema::nullableString('date-time'),
            'visit_type' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'provider_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'provider_specialty' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'facility_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'chief_complaint' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'assessment' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'plan' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'subjective' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'objective' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'raw_text' => ClinicalJsonSchema::nullableString(),
            'icd10_codes' => ClinicalJsonSchema::codes(),
            'cpt_codes' => ClinicalJsonSchema::codes(),
        ]);
    }
}
