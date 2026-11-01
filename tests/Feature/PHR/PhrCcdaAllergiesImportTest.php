<?php

namespace Tests\Feature\PHR;

use App\Models\PhrAllergy;

final class PhrCcdaAllergiesImportTest extends PhrCcdaSectionImportTestCase
{
    protected static function model(): string
    {
        return PhrAllergy::class;
    }

    protected static function fixture(): string
    {
        return 'allergies.xml';
    }

    protected static function keyColumn(): string
    {
        return 'substance';
    }

    protected static function entries(): array
    {
        return [
            'Synthetic antibiotic' => 'alg-display',
            'Synthetic nut' => 'alg-original',
            'Synthetic pollen' => 'alg-name',
            'Synthetic inactive substance' => 'alg-inactive',
            'RxNorm|9990305' => 'alg-code-only',
            // Nothing names it, so its own table row is read the way the
            // narrative-table reader reads it, rather than dropped.
            'Synthetic unnamed substance' => 'alg-unnamed',
        ];
    }

    protected static function expectedFields(): array
    {
        $none = array_fill_keys(array_values(self::entries()), null);

        return [
            'rxnorm_code' => [...$none, 'alg-display' => '9990301', 'alg-code-only' => '9990305'],
            'snomed_code' => [...$none, 'alg-original' => '9990302', 'alg-inactive' => '9990304'],
            'category' => [...$none,
                'alg-display' => 'medication', 'alg-original' => 'food', 'alg-name' => 'environment', 'alg-code-only' => 'medication',
            ],
            // Never the severity.
            'criticality' => [...$none, 'alg-display' => 'high', 'alg-original' => 'low', 'alg-inactive' => 'unable_to_assess'],
            'clinical_status' => [
                // Its status observation says so.
                'alg-display' => 'active',
                // Its concern is completed.
                'alg-original' => 'resolved',
                // It has ended.
                'alg-name' => 'resolved',
                'alg-inactive' => 'inactive',
                'alg-code-only' => 'active',
                'alg-unnamed' => 'active',
            ],
            'verification_status' => array_fill_keys(array_values(self::entries()), 'confirmed'),
            'reaction' => [...$none, 'alg-display' => 'Synthetic hives, Synthetic swelling'],
            // The allergy's own severity, else its first reaction's; never the criticality.
            'severity' => [...$none, 'alg-display' => 'moderate', 'alg-original' => 'severe'],
            'external_id' => [
                'alg-display' => 'ccda-allergy-'.sha1('id|2.25.9001|alg-display'),
                'alg-original' => 'ccda-allergy-'.sha1('id|2.25.9001|alg-original'),
                'alg-name' => 'ccda-allergy-'.sha1('id|2.25.9001|alg-name'),
                'alg-inactive' => 'ccda-allergy-'.sha1('id|2.25.9001|alg-inactive'),
                'alg-code-only' => 'ccda-allergy-'.sha1('id|2.25.9001|alg-code-only'),
                'alg-unnamed' => 'ccda-allergy-'.sha1('Synthetic unnamed substance|||01/01/2024|'),
            ],
        ];
    }

    protected static function negations(): array
    {
        return [
            'No known allergies' => [
                'assertion_type' => 'allergy_absence', 'scope' => 'allergies', 'observed_on' => '2026-01-02',
                'external_id' => 'ccda-negation-'.sha1('phr_allergy|id|2.25.9001|alg-nka'),
            ],
        ];
    }

    protected static function exportedRecord(): array
    {
        return ['substance' => 'Synthetic substance', 'reaction' => 'Synthetic reaction', 'severity' => 'mild'];
    }

    protected static function narrativeAdoption(): array
    {
        // The code-only allergy and the negation are added; the unnamed one
        // reads its row as before.
        return [
            'created' => 2,
            'updated' => 4,
            'unchanged' => 1,
            // By position, the narrative-table reader stored the criticality as
            // the severity.
            'narrative_key' => 'Synthetic antibiotic',
            'corrected_key' => 'Synthetic antibiotic',
        ];
    }

    protected static function earlierLoad(): array
    {
        return [
            'kind' => 'allergy',
            'key' => '2.25.9001|alg-display',
            'case_variant' => '2.25.9001|ALG-DISPLAY',
            'external_id' => 'ccda-allergy-'.sha1('id|2.25.9001|alg-display'),
        ];
    }

    protected static function earlierLoadColumns(): array
    {
        return ['substance' => 'Synthetic antibiotic'];
    }
}
