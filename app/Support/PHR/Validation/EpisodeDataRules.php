<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use Illuminate\Validation\Rule;

/**
 * An episode of care (#415): several encounters that tell one story, such as an earlier
 * admission and the stay it led to.
 */
final class EpisodeDataRules implements ClinicalDataRules
{
    /** What the episode is about: kind => label. Exported as EpisodeOfCare.type. */
    public const array KINDS = [
        'illness' => 'Illness',
        'hospitalization' => 'Hospitalization',
        'treatment' => 'Course of treatment',
        'rehabilitation' => 'Rehabilitation',
        'pregnancy' => 'Pregnancy',
        'other' => 'Other',
    ];

    /**
     * FHIR EpisodeOfCare.status, stored as FHIR names it. entered-in-error is left out: a
     * source withdrawing a claim retracts the record instead.
     */
    public const array STATUSES = ['planned', 'waitlist', 'active', 'onhold', 'finished', 'cancelled'];

    public static function rules(bool $partial = false): array
    {
        return [
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => ['nullable', 'string', Rule::in(array_keys(self::KINDS))],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'title' => ['type' => 'string', 'maxLength' => 255, 'description' => 'A short name for the episode, for example "Community-acquired pneumonia".'],
            'kind' => ['type' => ['string', 'null'], 'enum' => [...array_keys(self::KINDS), null], 'description' => 'What the episode is about: illness, hospitalization, treatment (a course of treatment), rehabilitation, pregnancy or other.'],
            'status' => ['type' => ['string', 'null'], 'enum' => [...self::STATUSES, null], 'description' => 'FHIR EpisodeOfCare.status. Without one, an export says finished once ended_at is set and active before.'],
            'started_at' => ClinicalJsonSchema::nullableString('date-time'),
            'ended_at' => ClinicalJsonSchema::nullableString('date-time'),
            'reason' => ClinicalJsonSchema::nullableString(maxLength: 10000) + ['description' => 'Why these encounters belong together, in words.'],
        ], ['title']);
    }
}
