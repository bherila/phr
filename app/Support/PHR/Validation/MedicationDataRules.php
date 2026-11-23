<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use App\Support\PHR\PhrMedicationCategory;
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
            'category' => ['nullable', 'string', Rule::in(PhrMedicationCategory::ALL)],
            'as_needed' => ['nullable', 'boolean'],
            'as_needed_reason' => ['nullable', 'string', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'rate' => ['nullable', 'string', 'max:100'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
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
            'category' => [
                'type' => ['string', 'null'],
                'enum' => [...PhrMedicationCategory::ALL, null],
                'description' => 'The medication\'s setting (FHIR medicationrequest-category): community for the patient\'s home medication list, inpatient for an order during a hospital stay, outpatient for one given in a clinic or emergency department, discharge for the list a hospital sent the patient home with. Null is unknown, never assumed to be community. Only community and unknown medications are on the home list and can have prescription fills.',
            ],
            'as_needed' => ['type' => ['boolean', 'null'], 'description' => 'Taken only as needed (PRN). Null when the source did not say.'],
            'as_needed_reason' => ClinicalJsonSchema::nullableString(maxLength: 255) + ['description' => 'What an as-needed medication is taken for, for example "pain" or "temperature > 38.0 C".'],
            'brand_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'rate' => ClinicalJsonSchema::nullableString(maxLength: 100) + ['description' => 'A continuous infusion\'s rate, for example "75 mL/h".'],
            'started_at' => ClinicalJsonSchema::nullableString('date-time') + ['description' => 'When an order started, when the source gives a time; started_on keeps the date. Changing started_on without sending started_at clears a time that no longer falls on the new date.'],
            'ended_at' => ClinicalJsonSchema::nullableString('date-time') + ['description' => 'When an order ended, when the source gives a time; ended_on keeps the date. Changing ended_on without sending ended_at clears a time that no longer falls on the new date.'],
        ], ['name']);
    }
}
