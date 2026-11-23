<?php

namespace App\Support\PHR;

/**
 * The coded encounter values a visit stores (issue #410), with the systems they come
 * from and the few exact crossings to other systems an import or export needs.
 *
 * - `encounter_class`: HL7 v3 ActCode encounter codes (the FHIR R4 `Encounter.class`
 *   value set).
 * - `encounter_state`: FHIR R4 `Encounter.status`. It is not `encounter_status`, which
 *   keeps the source's own appointment status text (EHI `APPT_STATUS_C_NAME`).
 * - `admit_source`: FHIR `admit-source` codes; `discharge_disposition`: FHIR
 *   `discharge-disposition` codes. Each has a free-text column beside it for the
 *   source's own words, which is all a record keeps when its code is from another
 *   system with no exact crossing.
 *
 * Crossings to C-CDA's NUBC patient status codes are listed only where the two codes
 * mean the same thing. Anything else is carried as text, never guessed into a code.
 */
final class PhrEncounterVocabulary
{
    public const string ACT_CODE_SYSTEM = 'http://terminology.hl7.org/CodeSystem/v3-ActCode';

    public const string ACT_CODE_OID = '2.16.840.1.113883.5.4';

    public const string ADMIT_SOURCE_SYSTEM = 'http://terminology.hl7.org/CodeSystem/admit-source';

    public const string DISCHARGE_DISPOSITION_SYSTEM = 'http://terminology.hl7.org/CodeSystem/discharge-disposition';

    /** NUBC UB-04 FL17 patient discharge status, what C-CDA's discharge disposition codes are. */
    public const string NUBC_DISCHARGE_OID = '2.16.840.1.113883.6.301.5';

    /** @var array<string, string> v3 ActCode encounter class => display */
    public const array CLASSES = [
        'AMB' => 'ambulatory',
        'EMER' => 'emergency',
        'IMP' => 'inpatient encounter',
        'OBSENC' => 'observation encounter',
        'SS' => 'short stay',
        'PRENC' => 'pre-admission',
        'ACUTE' => 'inpatient acute',
        'NONAC' => 'inpatient non-acute',
        'VR' => 'virtual',
        'HH' => 'home health',
        'FLD' => 'field',
    ];

    /** @var list<string> FHIR R4 Encounter.status */
    public const array STATES = [
        'planned',
        'arrived',
        'triaged',
        'in-progress',
        'onleave',
        'finished',
        'cancelled',
        'entered-in-error',
        'unknown',
    ];

    /** @var array<string, string> FHIR admit-source code => display */
    public const array ADMIT_SOURCES = [
        'hosp-trans' => 'Transferred from other hospital',
        'emd' => 'From accident/emergency department',
        'outp' => 'From outpatient department',
        'born' => 'Born in hospital',
        'gp' => 'General Practitioner referral',
        'mp' => 'Medical Practitioner/physician referral',
        'nursing' => 'From nursing home',
        'psych' => 'From psychiatric hospital',
        'rehab' => 'From rehabilitation facility',
        'other' => 'Other',
    ];

    /** @var array<string, string> FHIR discharge-disposition code => display */
    public const array DISCHARGE_DISPOSITIONS = [
        'home' => 'Home',
        'alt-home' => 'Alternative home',
        'other-hcf' => 'Other healthcare facility',
        'hosp' => 'Hospice',
        'long' => 'Long-term care',
        'aadvice' => 'Left against advice',
        'exp' => 'Expired',
        'psy' => 'Psychiatric hospital',
        'rehab' => 'Rehabilitation',
        'snf' => 'Skilled nursing facility',
        'oth' => 'Other',
    ];

    /** @var array<string, array{code: string, display: string}> FHIR discharge-disposition => the NUBC code that means the same */
    private const array NUBC_FOR_DISPOSITION = [
        'home' => ['code' => '01', 'display' => 'Discharged to home or self care (routine discharge)'],
        'snf' => ['code' => '03', 'display' => 'Discharged/transferred to skilled nursing facility (SNF)'],
        'aadvice' => ['code' => '07', 'display' => 'Left against medical advice or discontinued care'],
        'exp' => ['code' => '20', 'display' => 'Expired'],
        'rehab' => ['code' => '62', 'display' => 'Discharged/transferred to an inpatient rehabilitation facility'],
        'long' => ['code' => '63', 'display' => 'Discharged/transferred to a Medicare certified long term care hospital'],
        'psy' => ['code' => '65', 'display' => 'Discharged/transferred to a psychiatric hospital or psychiatric distinct part unit'],
    ];

    /** @var array<int|string, string> NUBC code => FHIR discharge-disposition, read only (NUBC is the narrower code); PHP keys "20" as an int */
    private const array DISPOSITION_FOR_NUBC = [
        '01' => 'home',
        '02' => 'other-hcf',
        '03' => 'snf',
        '07' => 'aadvice',
        '20' => 'exp',
        '50' => 'hosp',
        '51' => 'hosp',
        '62' => 'rehab',
        '63' => 'long',
        '65' => 'psy',
    ];

    public static function isClass(?string $code): bool
    {
        return $code !== null && isset(self::CLASSES[$code]);
    }

    public static function isState(?string $code): bool
    {
        return $code !== null && in_array($code, self::STATES, true);
    }

    public static function isAdmitSource(?string $code): bool
    {
        return $code !== null && isset(self::ADMIT_SOURCES[$code]);
    }

    public static function isDischargeDisposition(?string $code): bool
    {
        return $code !== null && isset(self::DISCHARGE_DISPOSITIONS[$code]);
    }

    /** A class code as stored, from a value whose case a source may not keep. */
    public static function classCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : null;

        return self::isClass($code) ? $code : null;
    }

    public static function stateCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtolower(trim($value)) : null;

        return self::isState($code) ? $code : null;
    }

    public static function admitSourceCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtolower(trim($value)) : null;

        return self::isAdmitSource($code) ? $code : null;
    }

    public static function dischargeDispositionCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtolower(trim($value)) : null;

        return self::isDischargeDisposition($code) ? $code : null;
    }

    /** @return array{code: string, display: string}|null */
    public static function nubcForDisposition(?string $disposition): ?array
    {
        return $disposition === null ? null : (self::NUBC_FOR_DISPOSITION[$disposition] ?? null);
    }

    public static function dispositionForNubc(?string $nubc): ?string
    {
        return $nubc === null ? null : (self::DISPOSITION_FOR_NUBC[trim($nubc)] ?? null);
    }
}
