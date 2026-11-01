<?php

namespace Tests\Feature\PHR;

use App\Models\PhrOfficeVisit;

final class PhrCcdaEncountersImportTest extends PhrCcdaSectionImportTestCase
{
    protected static function model(): string
    {
        return PhrOfficeVisit::class;
    }

    protected static function fixture(): string
    {
        return 'encounters.xml';
    }

    protected static function keyColumn(): string
    {
        return 'visit_type';
    }

    protected static function entries(): array
    {
        // The planned visit is not a record.
        return [
            'Synthetic office visit' => 'enc-display',
            'Synthetic telehealth' => 'enc-original',
            'CPT-4|99214' => 'enc-code-only',
        ];
    }

    protected static function expectedFields(): array
    {
        $none = array_fill_keys(array_values(self::entries()), null);

        return [
            'visit_date' => ['enc-display' => '2026-01-02', 'enc-original' => '2026-01-03', 'enc-code-only' => '2026-01-04'],
            // A date without a time of day is not a time.
            'visit_started_at' => [...$none, 'enc-display' => '2026-01-02 03:04:05'],
            'visit_ended_at' => [...$none, 'enc-display' => '2026-01-02 04:00:00'],
            // Never the department.
            'provider_name' => [...$none, 'enc-display' => 'Synthetic Clinician MD'],
            'provider_specialty' => [...$none, 'enc-display' => 'Synthetic family medicine'],
            // The service location, else the performing organization.
            'facility_name' => [...$none, 'enc-display' => 'Synthetic Department', 'enc-original' => 'Synthetic Virtual Clinic'],
            // Never the care team.
            'chief_complaint' => [...$none, 'enc-display' => 'Synthetic cough'],
            'assessment' => $none,
            'icd10_codes' => [...$none, 'enc-display' => [['code' => 'R99.1', 'description' => 'Synthetic diagnosis']]],
            'external_id' => [
                'enc-display' => 'ccda-visit-'.sha1('id|2.25.10001|enc-display'),
                'enc-original' => 'ccda-visit-'.sha1('id|2.25.10001|enc-original'),
                'enc-code-only' => 'ccda-visit-'.sha1('id|2.25.10001|enc-code-only'),
            ],
        ];
    }

    protected static function exportedRecord(): array
    {
        return ['visit_type' => 'Synthetic visit', 'visit_date' => null, 'provider_name' => null];
    }

    protected static function narrativeAdoption(): array
    {
        // The code-only visit does not reference its row and is added. The row
        // the narrative-table reader made of the planned visit is left as it is.
        return [
            'created' => 1,
            'updated' => 2,
            'unchanged' => 0,
            // By position, the narrative-table reader stored the department as
            // the provider.
            'narrative_key' => 'Synthetic office visit',
            'corrected_key' => 'Synthetic office visit',
        ];
    }

    protected static function earlierLoad(): array
    {
        return [
            'kind' => 'encounter',
            'key' => '2.25.10001|enc-display',
            'case_variant' => '2.25.10001|ENC-DISPLAY',
            'external_id' => 'ccda-visit-'.sha1('id|2.25.10001|enc-display'),
        ];
    }

    protected static function earlierLoadColumns(): array
    {
        return ['visit_type' => 'Synthetic office visit', 'visit_date' => '2026-01-02'];
    }
}
