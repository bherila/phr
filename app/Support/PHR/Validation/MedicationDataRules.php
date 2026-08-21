<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use Illuminate\Validation\Rule;

final class MedicationDataRules implements ClinicalDataRules
{
    public const array STATUSES = ['active', 'completed', 'discontinued', 'on_hold'];

    public static function rules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'rxnorm_code' => ['nullable', 'string', 'max:50'],
            'dose' => ['nullable', 'string', 'max:100'],
            'dose_unit' => ['nullable', 'string', 'max:50'],
            'route' => ['nullable', 'string', 'max:100'],
            'frequency' => ['nullable', 'string', 'max:100'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'prescriber_name' => ['nullable', 'string', 'max:255'],
            'reason_for_use' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'name' => ['type' => 'string', 'maxLength' => 255],
            'rxnorm_code' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'dose' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'dose_unit' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'route' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'frequency' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'started_on' => ClinicalJsonSchema::nullableString('date'),
            'ended_on' => ClinicalJsonSchema::nullableString('date'),
            'status' => ['type' => 'string', 'enum' => self::STATUSES],
            'prescriber_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'reason_for_use' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'raw_text' => ClinicalJsonSchema::nullableString(),
        ], ['name']);
    }
}
