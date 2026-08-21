<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;

final class ImmunizationDataRules implements ClinicalDataRules
{
    public static function rules(bool $partial = false): array
    {
        return [
            'vaccine_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'cvx_code' => ['nullable', 'string', 'max:20'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'administered_on' => ['nullable', 'date'],
            'dose_number' => ['nullable', 'integer', 'min:1'],
            'series_doses' => ['nullable', 'integer', 'min:1'],
            'site' => ['nullable', 'string', 'max:100'],
            'route' => ['nullable', 'string', 'max:100'],
            'administered_by' => ['nullable', 'string', 'max:255'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'vaccine_name' => ['type' => 'string', 'maxLength' => 255],
            'cvx_code' => ClinicalJsonSchema::nullableString(maxLength: 20),
            'manufacturer' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'lot_number' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'administered_on' => ClinicalJsonSchema::nullableString('date'),
            'dose_number' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'series_doses' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'site' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'route' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'administered_by' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'facility_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'notes' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'raw_text' => ClinicalJsonSchema::nullableString(),
        ], ['vaccine_name']);
    }
}
