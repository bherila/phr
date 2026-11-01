<?php

namespace Tests\Feature\PHR;

use App\Models\PhrCondition;

final class PhrCcdaProblemsImportTest extends PhrCcdaSectionImportTestCase
{
    protected static function model(): string
    {
        return PhrCondition::class;
    }

    protected static function fixture(): string
    {
        return 'problems.xml';
    }

    protected static function keyColumn(): string
    {
        return 'name';
    }

    protected static function entries(): array
    {
        return [
            'Synthetic chronic condition' => 'prob-display',
            'Synthetic referenced condition' => 'prob-original',
            'Synthetic past condition' => 'prob-abated',
            'Synthetic inactive condition' => 'prob-inactive',
            'Synthetic suspended condition' => 'prob-suspended',
            'SNOMED CT|9990106' => 'prob-code-only',
            // Nothing names it, so its own table row is read the way the
            // narrative-table reader reads it, rather than dropped.
            'Synthetic unnamed condition' => 'prob-unnamed',
        ];
    }

    protected static function expectedFields(): array
    {
        $none = array_fill_keys(array_values(self::entries()), null);

        return [
            'icd10_code' => [...$none,
                // From the SNOMED value's ICD-10-CM translation, or the value itself.
                'prob-display' => 'Z99.01', 'prob-original' => 'Z99.02',
                // By position, the narrative-table reader's code is the noted date.
                'prob-unnamed' => '01/01/2025',
            ],
            'snomed_code' => [...$none,
                'prob-display' => '9990101', 'prob-abated' => '9990103', 'prob-inactive' => '9990104',
                'prob-suspended' => '9990105', 'prob-code-only' => '9990106',
            ],
            'onset_date' => [...array_fill_keys(array_values(self::entries()), '2025-01-01'), 'prob-unnamed' => null],
            'abated_date' => [...$none, 'prob-abated' => '2025-06-01'],
            'clinical_status' => [
                // Its status observation says so.
                'prob-display' => 'active',
                // Its concern is completed: no longer tracked, which says
                // nothing about whether the problem ended.
                'prob-original' => 'active',
                // It has abated.
                'prob-abated' => 'resolved',
                'prob-inactive' => 'inactive',
                // Likewise a suspended concern.
                'prob-suspended' => 'active',
                'prob-code-only' => 'active',
                'prob-unnamed' => 'active',
            ],
            'verification_status' => array_fill_keys(array_values(self::entries()), 'confirmed'),
            'severity' => [...$none, 'prob-display' => 'moderate'],
            'external_id' => [
                'prob-display' => 'ccda-condition-'.sha1('id|2.25.6001|prob-display'),
                'prob-original' => 'ccda-condition-'.sha1('id|2.25.6001|prob-original'),
                'prob-abated' => 'ccda-condition-'.sha1('id|2.25.6001|prob-abated'),
                'prob-inactive' => 'ccda-condition-'.sha1('id|2.25.6001|prob-inactive'),
                'prob-suspended' => 'ccda-condition-'.sha1('id|2.25.6001|prob-suspended'),
                'prob-code-only' => 'ccda-condition-'.sha1('id|2.25.6001|prob-code-only'),
                'prob-unnamed' => 'ccda-condition-'.sha1('Synthetic unnamed condition|01/01/2025|'),
            ],
        ];
    }

    protected static function negations(): array
    {
        return [
            'No known problems' => [
                'assertion_type' => 'condition_absence', 'scope' => 'problems', 'observed_on' => '2026-01-02',
                'external_id' => 'ccda-negation-'.sha1('phr_problem_list|id|2.25.6001|prob-negated'),
            ],
        ];
    }

    protected static function exportedRecord(): array
    {
        return ['name' => 'Synthetic condition', 'icd10_code' => null, 'clinical_status' => 'active', 'onset_date' => null];
    }

    protected static function narrativeAdoption(): array
    {
        // The code-only observation and the negation are added; the unnamed
        // one reads its row as before.
        return [
            'created' => 2,
            'updated' => 5,
            'unchanged' => 1,
            'narrative_key' => 'Synthetic chronic condition',
            'corrected_key' => 'Synthetic chronic condition',
        ];
    }

    protected static function earlierLoad(): array
    {
        return [
            'kind' => 'condition',
            'key' => '2.25.6001|prob-display',
            'case_variant' => '2.25.6001|PROB-DISPLAY',
            'external_id' => 'ccda-condition-'.sha1('id|2.25.6001|prob-display'),
        ];
    }

    protected static function earlierLoadColumns(): array
    {
        return ['name' => 'Synthetic chronic condition'];
    }
}
