<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use Illuminate\Validation\Rule;

final class ConditionDataRules implements ClinicalDataRules
{
    public const array CLINICAL_STATUSES = ['active', 'recurrence', 'relapse', 'inactive', 'remission', 'resolved'];

    public const array VERIFICATION_STATUSES = ['unconfirmed', 'provisional', 'differential', 'confirmed', 'refuted', 'entered_in_error'];

    public const array SEVERITIES = ['mild', 'moderate', 'severe'];

    public static function rules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'icd10_code' => ['nullable', 'string', 'max:20'],
            'snomed_code' => ['nullable', 'string', 'max:50'],
            'onset_date' => ['nullable', 'date'],
            'abated_date' => ['nullable', 'date'],
            'clinical_status' => ['sometimes', 'string', Rule::in(self::CLINICAL_STATUSES)],
            'verification_status' => ['sometimes', 'string', Rule::in(self::VERIFICATION_STATUSES)],
            'severity' => ['nullable', 'string', Rule::in(self::SEVERITIES)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'name' => ['type' => 'string', 'maxLength' => 255],
            'icd10_code' => ClinicalJsonSchema::nullableString(maxLength: 20),
            'snomed_code' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'onset_date' => ClinicalJsonSchema::nullableString('date'),
            'abated_date' => ClinicalJsonSchema::nullableString('date'),
            'clinical_status' => ['type' => 'string', 'enum' => self::CLINICAL_STATUSES],
            'verification_status' => ['type' => 'string', 'enum' => self::VERIFICATION_STATUSES],
            'severity' => ['type' => ['string', 'null'], 'enum' => [...self::SEVERITIES, null]],
            'notes' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'raw_text' => ClinicalJsonSchema::nullableString(),
        ], ['name']);
    }
}
