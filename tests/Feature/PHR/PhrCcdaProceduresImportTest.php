<?php

namespace Tests\Feature\PHR;

use App\Models\PhrProcedure;

final class PhrCcdaProceduresImportTest extends PhrCcdaSectionImportTestCase
{
    protected static function model(): string
    {
        return PhrProcedure::class;
    }

    protected static function fixture(): string
    {
        return 'procedures.xml';
    }

    protected static function keyColumn(): string
    {
        return 'name';
    }

    protected static function entries(): array
    {
        // The planned procedure is not a record.
        return [
            'Synthetic repair' => 'proc-display',
            'Synthetic referenced test' => 'proc-original',
            'Synthetic counseling' => 'proc-act',
            'CPT-4|99904' => 'proc-code-only',
            // Nothing names it, so its own table row is read the way the
            // narrative-table reader reads it, rather than dropped.
            'Synthetic unnamed procedure' => 'proc-unnamed',
        ];
    }

    protected static function expectedFields(): array
    {
        $none = array_fill_keys(array_values(self::entries()), null);

        return [
            'cpt_code' => [...$none, 'proc-display' => '99901', 'proc-original' => '99902', 'proc-code-only' => '99904'],
            'snomed_code' => [...$none, 'proc-display' => '9990201', 'proc-act' => '9990203'],
            'performed_at' => [...$none,
                'proc-display' => '2026-01-02 03:04:05', 'proc-act' => '2026-01-02 03:04:05', 'proc-code-only' => '2026-01-02 03:04:05',
                // A date without a time of day is not a time.
                'proc-original' => null,
            ],
            'performed_on' => [...$none,
                'proc-display' => '2026-01-02', 'proc-original' => '2026-01-02', 'proc-act' => '2026-01-02', 'proc-code-only' => '2026-01-02',
                // By position, the narrative-table reader's date is the provider column, which is empty.
                'proc-unnamed' => null,
            ],
            'performer_name' => [...$none, 'proc-display' => 'Dr. Synthetic Performer'],
            'performer_specialty' => [...$none, 'proc-display' => 'Synthetic specialty'],
            'facility_name' => [...$none, 'proc-display' => 'Synthetic Surgical Center', 'proc-original' => 'Synthetic Clinic'],
            'status' => [
                'proc-display' => 'completed', 'proc-original' => 'in_progress', 'proc-act' => 'cancelled', 'proc-code-only' => 'completed',
                // By position, the narrative-table reader's status is the date column.
                'proc-unnamed' => '01/02/2026',
            ],
            'reason' => [...$none, 'proc-display' => 'Synthetic reason'],
            'external_id' => [
                'proc-display' => 'ccda-procedure-'.sha1('id|2.25.7001|proc-display'),
                'proc-original' => 'ccda-procedure-'.sha1('id|2.25.7001|proc-original'),
                'proc-act' => 'ccda-procedure-'.sha1('id|2.25.7001|proc-act'),
                'proc-code-only' => 'ccda-procedure-'.sha1('id|2.25.7001|proc-code-only'),
                'proc-unnamed' => 'ccda-procedure-'.sha1('Synthetic unnamed procedure||01/02/2026|'),
            ],
        ];
    }

    protected static function negations(): array
    {
        return [
            'Synthetic skipped procedure not performed' => [
                'assertion_type' => 'other', 'scope' => 'procedures', 'observed_on' => '2026-01-02',
                'external_id' => 'ccda-negation-'.sha1('phr_procedure|id|2.25.7001|proc-negated'),
            ],
        ];
    }

    protected static function exportedRecord(): array
    {
        return ['name' => 'Synthetic procedure', 'performed_on' => null, 'status' => 'completed'];
    }

    protected static function narrativeAdoption(): array
    {
        // The code-only procedure and the negation are added; the unnamed one
        // reads its row as before. The row the narrative-table reader made of
        // the planned procedure is left as it is.
        return [
            'created' => 2,
            'updated' => 3,
            'unchanged' => 1,
            'narrative_key' => 'Synthetic repair',
            'corrected_key' => 'Synthetic repair',
        ];
    }

    protected static function earlierLoad(): array
    {
        return [
            'kind' => 'procedure',
            'key' => '2.25.7001|proc-display',
            'case_variant' => '2.25.7001|PROC-DISPLAY',
            'external_id' => 'ccda-procedure-'.sha1('id|2.25.7001|proc-display'),
        ];
    }

    protected static function earlierLoadColumns(): array
    {
        return ['name' => 'Synthetic repair', 'status' => 'completed'];
    }
}
