<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatientVital;

final class PhrCcdaVitalSignsImportTest extends PhrCcdaSectionImportTestCase
{
    protected static function model(): string
    {
        return PhrPatientVital::class;
    }

    protected static function fixture(): string
    {
        return 'vital-signs.xml';
    }

    protected static function keyColumn(): string
    {
        return 'vital_name';
    }

    protected static function entries(): array
    {
        return [
            'Synthetic pressure' => 'vital-display',
            'Synthetic pulse' => 'vital-original',
            'Synthetic height' => 'vital-text',
            'SYNTHETIC|SYN-VIT' => 'vital-code-only',
            'Synthetic weight' => 'vital-translation',
            'Synthetic temperature' => 'vital-organizer',
            'Synthetic saturation' => 'vital-date-only',
            'Synthetic respiration' => 'no-id',
            // Nothing names it, so its own table row is read the way the
            // narrative-table reader reads it, rather than dropped.
            '21' => 'vital-unnamed',
        ];
    }

    protected static function expectedFields(): array
    {
        return [
            'vital_value' => [
                'vital-display' => '118', 'vital-original' => '64', 'vital-text' => '170', 'vital-code-only' => '97',
                'vital-translation' => '150', 'vital-organizer' => '36.8', 'vital-date-only' => '99', 'no-id' => '14',
                'vital-unnamed' => '01/02/2026 3:04 AM PST',
            ],
            'value_numeric' => [
                'vital-display' => '118', 'vital-original' => '64', 'vital-text' => '170', 'vital-code-only' => '97',
                'vital-translation' => '150', 'vital-organizer' => '36.8', 'vital-date-only' => '99', 'no-id' => '14',
                'vital-unnamed' => null,
            ],
            'unit' => [
                'vital-display' => 'mm[Hg]', 'vital-original' => '/min', 'vital-text' => 'cm', 'vital-code-only' => '1',
                // A non-UCUM unit comes from the translation's original text.
                'vital-translation' => 'LBS', 'vital-organizer' => 'Cel', 'vital-date-only' => '%', 'no-id' => '/min',
                'vital-unnamed' => null,
            ],
            'observed_at' => [
                'vital-display' => '2026-01-02 03:04:05', 'vital-original' => '2026-01-02 03:04:05', 'vital-text' => '2026-01-02 03:04:05',
                'vital-code-only' => '2026-01-02 03:04:05', 'vital-translation' => '2026-01-02 03:04:05',
                // No time of its own: the organizer's.
                'vital-organizer' => '2026-01-02 03:04:05',
                // A date without a time of day is not a time.
                'vital-date-only' => null,
                'no-id' => '2026-01-02 03:04:05', 'vital-unnamed' => null,
            ],
            'vital_date' => [
                'vital-display' => '2026-01-02', 'vital-original' => '2026-01-02', 'vital-text' => '2026-01-02', 'vital-code-only' => '2026-01-02',
                'vital-translation' => '2026-01-02', 'vital-organizer' => '2026-01-02', 'vital-date-only' => '2026-01-03', 'no-id' => '2026-01-02',
                // Its row's first cell is a name, not a date.
                'vital-unnamed' => null,
            ],
            'body_site' => [
                'vital-display' => 'Synthetic arm', 'vital-original' => null, 'vital-text' => null, 'vital-code-only' => null,
                'vital-translation' => null, 'vital-organizer' => null, 'vital-date-only' => null, 'no-id' => null, 'vital-unnamed' => null,
            ],
            'external_id' => [
                'vital-display' => 'ccda-vital-'.sha1('id|2.25.4001|vital-display'),
                'vital-original' => 'ccda-vital-'.sha1('id|2.25.4001|vital-original'),
                'vital-text' => 'ccda-vital-'.sha1('id|2.25.4001|vital-text'),
                'vital-code-only' => 'ccda-vital-'.sha1('id|2.25.4001|vital-code-only'),
                'vital-translation' => 'ccda-vital-'.sha1('id|2.25.4001|vital-translation'),
                'vital-organizer' => 'ccda-vital-'.sha1('id|2.25.4001|vital-organizer'),
                'vital-date-only' => 'ccda-vital-'.sha1('id|2.25.4001|vital-date-only'),
                // No id: what it says identifies it.
                'no-id' => 'ccda-vital-'.sha1('content|Synthetic respiration|2026-01-02T03:04:05-08:00|14|/min'),
                // The narrative-table reader's id for its row.
                'vital-unnamed' => 'ccda-vital-'.sha1('Synthetic unnamed vital|21|01/02/2026 3:04 AM PST|'),
            ],
        ];
    }

    protected static function exportedRecord(): array
    {
        return ['vital_name' => 'Synthetic vital', 'vital_value' => '1', 'unit' => 'unit'];
    }

    protected static function narrativeAdoption(): array
    {
        // Every observation references its row but the code-only one, which is
        // added beside the row the narrative reader made of it. The unnamed one
        // reads its row as before.
        return [
            'created' => 1,
            'updated' => 7,
            'unchanged' => 1,
            // By position, the narrative-table reader read the reading as the name.
            'narrative_key' => '118 mm[Hg]',
            'corrected_key' => 'Synthetic pressure',
        ];
    }

    protected static function earlierLoad(): array
    {
        return [
            'kind' => 'vital',
            'key' => '2.25.4001|vital-display',
            'case_variant' => '2.25.4001|VITAL-DISPLAY',
            'external_id' => 'ccda-vital-'.sha1('id|2.25.4001|vital-display'),
        ];
    }

    protected static function earlierLoadColumns(): array
    {
        return ['vital_name' => 'Synthetic pressure', 'vital_value' => '118'];
    }
}
