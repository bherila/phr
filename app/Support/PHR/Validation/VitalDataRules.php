<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;

final class VitalDataRules implements ClinicalDataRules
{
    public static function rules(bool $partial = false): array
    {
        return [
            'vital_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'vital_date' => ['nullable', 'date'],
            'observed_at' => ['nullable', 'date'],
            'vital_value' => ['nullable', 'string', 'max:255'],
            'value_numeric' => ['nullable', 'numeric'],
            'value_numeric_secondary' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'secondary_unit' => ['nullable', 'string', 'max:50'],
            'body_site' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public static function jsonSchema(): array
    {
        $numeric = ['type' => ['number', 'string', 'null']];

        return ClinicalJsonSchema::object([
            'vital_name' => ['type' => 'string', 'maxLength' => 255],
            'vital_date' => ClinicalJsonSchema::nullableString('date'),
            'observed_at' => ClinicalJsonSchema::nullableString('date-time'),
            'vital_value' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'value_numeric' => $numeric,
            'value_numeric_secondary' => $numeric,
            'unit' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'secondary_unit' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'body_site' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'source' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'notes' => ClinicalJsonSchema::nullableString(maxLength: 10000),
        ], ['vital_name']);
    }
}
