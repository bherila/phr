<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use Illuminate\Validation\Rule;

final class AllergyDataRules implements ClinicalDataRules
{
    public const array CATEGORIES = ['food', 'medication', 'environment', 'biologic'];

    public const array CRITICALITIES = ['low', 'high', 'unable_to_assess'];

    public const array CLINICAL_STATUSES = ['active', 'inactive', 'resolved'];

    public const array VERIFICATION_STATUSES = ['unconfirmed', 'confirmed', 'refuted', 'entered_in_error'];

    public const array SEVERITIES = ['mild', 'moderate', 'severe'];

    public static function rules(bool $partial = false): array
    {
        return [
            'substance' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'rxnorm_code' => ['nullable', 'string', 'max:50'],
            'snomed_code' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', Rule::in(self::CATEGORIES)],
            'criticality' => ['nullable', 'string', Rule::in(self::CRITICALITIES)],
            'clinical_status' => ['sometimes', 'string', Rule::in(self::CLINICAL_STATUSES)],
            'verification_status' => ['sometimes', 'string', Rule::in(self::VERIFICATION_STATUSES)],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', 'string', Rule::in(self::SEVERITIES)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'substance' => ['type' => 'string', 'maxLength' => 255],
            'rxnorm_code' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'snomed_code' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'category' => ['type' => ['string', 'null'], 'enum' => [...self::CATEGORIES, null]],
            'criticality' => ['type' => ['string', 'null'], 'enum' => [...self::CRITICALITIES, null]],
            'clinical_status' => ['type' => 'string', 'enum' => self::CLINICAL_STATUSES],
            'verification_status' => ['type' => 'string', 'enum' => self::VERIFICATION_STATUSES],
            'reaction' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'severity' => ['type' => ['string', 'null'], 'enum' => [...self::SEVERITIES, null]],
            'notes' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'raw_text' => ClinicalJsonSchema::nullableString(),
        ], ['substance']);
    }
}
