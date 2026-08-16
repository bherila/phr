<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use Illuminate\Validation\Rule;

final class ProcedureDataRules implements ClinicalDataRules
{
    public const array STATUSES = [
        'preparation',
        'in_progress',
        'completed',
        'cancelled',
        'entered_in_error',
    ];

    public static function rules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'cpt_code' => ['nullable', 'string', 'max:20'],
            'snomed_code' => ['nullable', 'string', 'max:50'],
            'performed_at' => ['nullable', 'date'],
            'performed_on' => ['nullable', 'date'],
            'performer_name' => ['nullable', 'string', 'max:255'],
            'performer_specialty' => ['nullable', 'string', 'max:100'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'reason' => ['nullable', 'string', 'max:10000'],
            'outcome' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'name' => ['type' => 'string', 'maxLength' => 255],
            'cpt_code' => ClinicalJsonSchema::nullableString(maxLength: 20),
            'snomed_code' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'performed_at' => ClinicalJsonSchema::nullableString('date-time'),
            'performed_on' => ClinicalJsonSchema::nullableString('date'),
            'performer_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'performer_specialty' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'facility_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'status' => ['type' => 'string', 'enum' => self::STATUSES],
            'reason' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'outcome' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'notes' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'raw_text' => ClinicalJsonSchema::nullableString(),
        ], ['name']);
    }
}
