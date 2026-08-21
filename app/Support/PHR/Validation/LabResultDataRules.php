<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;

final class LabResultDataRules implements ClinicalDataRules
{
    public static function rules(bool $partial = false): array
    {
        return [
            'test_name' => ['nullable', 'string', 'max:255'],
            'collection_datetime' => ['nullable', 'date'],
            'result_datetime' => ['nullable', 'date'],
            'result_status' => ['nullable', 'string', 'max:50'],
            'ordering_provider' => ['nullable', 'string', 'max:100'],
            'resulting_lab' => ['nullable', 'string', 'max:100'],
            'analyte' => [$partial ? 'sometimes' : 'required', 'string', 'max:100'],
            'value' => ['nullable', 'string', 'max:255'],
            'value_numeric' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'range_min' => ['nullable', 'numeric'],
            'range_max' => ['nullable', 'numeric'],
            'range_unit' => ['nullable', 'string', 'max:50'],
            'reference_range_text' => ['nullable', 'string', 'max:255'],
            'normal_value' => ['nullable', 'string', 'max:100'],
            'abnormal_flag' => ['nullable', 'string', 'max:50'],
            'message_from_provider' => ['nullable', 'string', 'max:10000'],
            'result_comment' => ['nullable', 'string', 'max:10000'],
            'lab_director' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public static function jsonSchema(): array
    {
        $numeric = ['type' => ['number', 'string', 'null']];

        return ClinicalJsonSchema::object([
            'test_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'collection_datetime' => ClinicalJsonSchema::nullableString('date-time'),
            'result_datetime' => ClinicalJsonSchema::nullableString('date-time'),
            'result_status' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'ordering_provider' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'resulting_lab' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'analyte' => ['type' => 'string', 'maxLength' => 100],
            'value' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'value_numeric' => $numeric,
            'unit' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'range_min' => $numeric,
            'range_max' => $numeric,
            'range_unit' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'reference_range_text' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'normal_value' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'abnormal_flag' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'message_from_provider' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'result_comment' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'lab_director' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'source' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'notes' => ClinicalJsonSchema::nullableString(maxLength: 10000),
        ], ['analyte']);
    }
}
