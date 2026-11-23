<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use App\Services\PHR\Import\Ehi\Mapping\Encounter\EhiEncounterCategory;
use App\Support\PHR\PhrEncounterVocabulary;
use Illuminate\Validation\Rule;

final class OfficeVisitDataRules implements ClinicalDataRules
{
    /** What kind of event a visit records; EhiEncounterCategory's vocabulary. */
    public const array CATEGORIES = [
        EhiEncounterCategory::CLINICAL,
        EhiEncounterCategory::TELEPHONE,
        EhiEncounterCategory::PORTAL,
        EhiEncounterCategory::CANCELLED,
        EhiEncounterCategory::ADMINISTRATIVE,
        EhiEncounterCategory::UNKNOWN,
    ];

    /**
     * The fields one encounter diagnosis may carry: what EHI writes (line, name and its
     * flags) and what a standard source states (a code, its system, and its FHIR
     * diagnosis-role use). The list's order is the source's order.
     */
    public const array DIAGNOSIS_KEYS = ['line', 'name', 'code', 'code_system', 'use', 'primary', 'chronic', 'ed_impression', 'qualifier', 'annotation', 'comments'];

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
            'icd10_codes' => ['nullable', 'array', 'max:100'],
            'icd10_codes.*' => ['array:code,description'],
            'icd10_codes.*.code' => ['required', 'string', 'max:20'],
            'icd10_codes.*.description' => ['required', 'string', 'max:255'],
            'cpt_codes' => ['nullable', 'array', 'max:100'],
            'cpt_codes.*' => ['array:code,description'],
            'cpt_codes.*.code' => ['required', 'string', 'max:20'],
            'cpt_codes.*.description' => ['required', 'string', 'max:255'],
            'encounter_category' => ['nullable', 'string', Rule::in(self::CATEGORIES)],
            'encounter_class' => ['nullable', 'string', Rule::in(array_keys(PhrEncounterVocabulary::CLASSES))],
            'encounter_state' => ['nullable', 'string', Rule::in(PhrEncounterVocabulary::STATES)],
            'location' => ['nullable', 'string', 'max:255'],
            'admit_source' => ['nullable', 'string', Rule::in(array_keys(PhrEncounterVocabulary::ADMIT_SOURCES))],
            'admit_source_text' => ['nullable', 'string', 'max:255'],
            'discharge_disposition' => ['nullable', 'string', Rule::in(array_keys(PhrEncounterVocabulary::DISCHARGE_DISPOSITIONS))],
            'discharge_disposition_text' => ['nullable', 'string', 'max:255'],
            'encounter_diagnoses' => ['nullable', 'array', 'max:100'],
            'encounter_diagnoses.*' => ['array:'.implode(',', self::DIAGNOSIS_KEYS)],
            'encounter_diagnoses.*.name' => ['required', 'string', 'max:255'],
            'encounter_diagnoses.*.line' => ['nullable', 'string', 'max:20'],
            'encounter_diagnoses.*.code' => ['nullable', 'string', 'max:64'],
            'encounter_diagnoses.*.code_system' => ['nullable', 'string', 'max:255'],
            'encounter_diagnoses.*.use' => ['nullable', 'string', 'max:32'],
            'encounter_diagnoses.*.primary' => ['nullable', 'boolean'],
            'encounter_diagnoses.*.chronic' => ['nullable', 'boolean'],
            'encounter_diagnoses.*.ed_impression' => ['nullable', 'boolean'],
            'encounter_diagnoses.*.qualifier' => ['nullable', 'string', 'max:255'],
            'encounter_diagnoses.*.annotation' => ['nullable', 'string', 'max:10000'],
            'encounter_diagnoses.*.comments' => ['nullable', 'string', 'max:10000'],
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
            'icd10_codes' => ClinicalJsonSchema::codes(),
            'cpt_codes' => ClinicalJsonSchema::codes(),
            'encounter_category' => self::nullableEnum(self::CATEGORIES, 'What kind of event the visit records. Only clinical and telephone encounters are visits a person sees as care.'),
            'encounter_class' => self::nullableEnum(array_keys(PhrEncounterVocabulary::CLASSES), 'HL7 v3 ActCode encounter class: AMB ambulatory, EMER emergency, IMP inpatient, OBSENC observation, SS short stay, PRENC pre-admission, ACUTE inpatient acute, NONAC inpatient non-acute, VR virtual, HH home health, FLD field.'),
            'encounter_state' => self::nullableEnum(PhrEncounterVocabulary::STATES, 'FHIR Encounter.status: in-progress for a stay still under way, finished once it ended. encounter_status, which is read-only here, is the source\'s own appointment status text.'),
            'location' => ClinicalJsonSchema::nullableString(maxLength: 255) + ['description' => 'The unit, ward or location within the facility, for example a telemetry unit.'],
            'admit_source' => self::nullableEnum(array_keys(PhrEncounterVocabulary::ADMIT_SOURCES), 'FHIR admit-source code: emd for the emergency department, hosp-trans for a transfer, and so on.'),
            'admit_source_text' => ClinicalJsonSchema::nullableString(maxLength: 255) + ['description' => 'The admit source as the source worded it.'],
            'discharge_disposition' => self::nullableEnum(array_keys(PhrEncounterVocabulary::DISCHARGE_DISPOSITIONS), 'FHIR discharge-disposition code, for a stay that ended.'),
            'discharge_disposition_text' => ClinicalJsonSchema::nullableString(maxLength: 255) + ['description' => 'The discharge disposition as the source worded it.'],
            'encounter_diagnoses' => [
                'type' => ['array', 'null'],
                'maxItems' => 100,
                'description' => 'Diagnoses recorded on this encounter, in the source\'s order. They stay on the visit and are not conditions.',
                'items' => ClinicalJsonSchema::object([
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'line' => ClinicalJsonSchema::nullableString(maxLength: 20),
                    'code' => ClinicalJsonSchema::nullableString(maxLength: 64),
                    'code_system' => ClinicalJsonSchema::nullableString(maxLength: 255) + ['description' => 'The code\'s system URI, for example http://hl7.org/fhir/sid/icd-10-cm.'],
                    'use' => ClinicalJsonSchema::nullableString(maxLength: 32) + ['description' => 'FHIR diagnosis-role code: AD admission, DD discharge, CC chief complaint, CM comorbidity, billing.'],
                    'primary' => ['type' => ['boolean', 'null']],
                    'chronic' => ['type' => ['boolean', 'null']],
                    'ed_impression' => ['type' => ['boolean', 'null']],
                    'qualifier' => ClinicalJsonSchema::nullableString(maxLength: 255),
                    'annotation' => ClinicalJsonSchema::nullableString(maxLength: 10000),
                    'comments' => ClinicalJsonSchema::nullableString(maxLength: 10000),
                ], ['name']),
            ],
        ]);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function nullableEnum(array $values, string $description): array
    {
        return ['type' => ['string', 'null'], 'enum' => [...$values, null], 'description' => $description];
    }
}
