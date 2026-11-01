<?php

namespace Tests\Feature\PHR;

use App\Models\PhrImmunization;
use Illuminate\Database\Eloquent\Model;

final class PhrCcdaImmunizationsImportTest extends PhrCcdaSectionImportTestCase
{
    protected static function model(): string
    {
        return PhrImmunization::class;
    }

    protected static function fixture(): string
    {
        return 'immunizations.xml';
    }

    protected static function keyColumn(): string
    {
        return 'vaccine_name';
    }

    /** Two doses of one vaccine share its name. */
    protected function rowKey(Model $row): string
    {
        $date = $row->getAttribute('administered_on');

        return $row->getAttribute('vaccine_name').'@'.($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : '');
    }

    protected static function entries(): array
    {
        // The planned dose is not a record.
        return [
            'Synthetic vaccine A@2025-01-01' => 'imm-display',
            'Synthetic vaccine A@2025-02-01' => 'imm-second-dose',
            'Synthetic referenced vaccine@2025-01-01' => 'imm-original',
            'Synthetic named vaccine@2025-01-01' => 'imm-name',
            'CVX|904@2025-01-01' => 'imm-code-only',
            // Nothing names it, so its own table row is read the way the
            // narrative-table reader reads it, rather than dropped.
            'Synthetic unnamed vaccine@2025-01-01' => 'imm-unnamed',
        ];
    }

    protected static function expectedFields(): array
    {
        $none = array_fill_keys(array_values(self::entries()), null);

        return [
            'cvx_code' => [...$none,
                'imm-display' => '901', 'imm-second-dose' => '901', 'imm-original' => '902', 'imm-code-only' => '904',
            ],
            'manufacturer' => [...$none, 'imm-display' => 'Synthetic Manufacturer'],
            // Never the next due date.
            'lot_number' => [...$none, 'imm-display' => 'SYN-LOT-1'],
            'administered_on' => [...array_fill_keys(array_values(self::entries()), '2025-01-01'), 'imm-second-dose' => '2025-02-01'],
            'dose_number' => [...$none, 'imm-display' => 2],
            'dose_amount' => [...$none, 'imm-display' => '0.50'],
            'dose_unit' => [...$none, 'imm-display' => 'mL'],
            'route' => [...$none, 'imm-display' => 'Synthetic intramuscular'],
            'site' => [...$none, 'imm-display' => 'Synthetic left arm'],
            'administered_by' => [...$none, 'imm-display' => 'Synthetic Nurse'],
            'facility_name' => [...$none, 'imm-display' => 'Synthetic Clinic'],
            'external_id' => [
                'imm-display' => 'ccda-immunization-'.sha1('id|2.25.8001|imm-display'),
                'imm-second-dose' => 'ccda-immunization-'.sha1('id|2.25.8001|imm-second-dose'),
                'imm-original' => 'ccda-immunization-'.sha1('id|2.25.8001|imm-original'),
                'imm-name' => 'ccda-immunization-'.sha1('id|2.25.8001|imm-name'),
                'imm-code-only' => 'ccda-immunization-'.sha1('id|2.25.8001|imm-code-only'),
                'imm-unnamed' => 'ccda-immunization-'.sha1('Synthetic unnamed vaccine|01/01/2025|'),
            ],
        ];
    }

    protected static function negations(): array
    {
        return [
            'Synthetic refused vaccine not given' => [
                'assertion_type' => 'immunization_exception', 'scope' => 'immunizations', 'observed_on' => '2026-01-02',
                'external_id' => 'ccda-negation-'.sha1('phr_immunization|id|2.25.8001|imm-refused'),
            ],
        ];
    }

    protected static function exportedRecord(): array
    {
        return ['vaccine_name' => 'Synthetic vaccine', 'administered_on' => null, 'lot_number' => null];
    }

    protected static function narrativeAdoption(): array
    {
        // The second dose shares the first's row, which the first adopts; it,
        // the code-only dose and the refusal are added. The named vaccine's row
        // already held its facts, and the unnamed one reads its row as before.
        // The row the narrative-table reader made of the planned dose is left
        // as it is.
        return [
            'created' => 3,
            'updated' => 2,
            'unchanged' => 2,
            'narrative_key' => 'Synthetic vaccine A',
            'corrected_key' => 'Synthetic vaccine A',
        ];
    }

    protected static function earlierLoad(): array
    {
        // An earlier load keyed immunizations by the id's extension alone.
        return [
            'kind' => 'immunization',
            'key' => 'imm-display',
            'case_variant' => 'IMM-DISPLAY',
            'external_id' => 'ccda-immunization-'.sha1('id|2.25.8001|imm-display'),
        ];
    }

    protected static function earlierLoadColumns(): array
    {
        return ['vaccine_name' => 'Synthetic vaccine A', 'administered_on' => '2025-01-01'];
    }
}
