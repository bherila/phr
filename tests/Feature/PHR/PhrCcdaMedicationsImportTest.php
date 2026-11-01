<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedication;
use PHPUnit\Framework\Attributes\DataProvider;

final class PhrCcdaMedicationsImportTest extends PhrCcdaSectionImportTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function doseRanges(): array
    {
        return [
            'both bounds' => ['<low value="1" unit="1"/><high value="2" unit="1"/>', '1-2', 'Synthetic tablet'],
            'lower bound only' => ['<low value="5" unit="mg"/>', '>=5', 'mg'],
            'exclusive lower bound' => ['<low value="5" unit="mg" inclusive="false"/>', '>5', 'mg'],
            'upper bound only' => ['<high value="10" unit="mg"/>', '<=10', 'mg'],
        ];
    }

    public function test_activities_without_ids_that_differ_only_in_regimen_are_two_medications(): void
    {
        $patient = $this->ccdaPatient();
        $activity = static fn (string $regimen): string => <<<XML
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id nullFlavor="NI"/>
                <statusCode code="active"/>
                <effectiveTime xsi:type="IVL_TS"><low value="20260101"/></effectiveTime>
                {$regimen}
                <doseQuantity value="5" unit="mg"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9990091" codeSystem="2.16.840.1.113883.6.88" displayName="Synthetic regimen medicine"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML;

        $this->importCcda($this->ccdaSectionDocument(
            '<templateId root="2.16.840.1.113883.10.20.22.2.1.1" extension="2014-06-09"/><title>Medications</title>'
            .$activity('<effectiveTime xsi:type="PIVL_TS" operator="A"><period value="12" unit="h"/></effectiveTime>')
            .$activity('<effectiveTime xsi:type="PIVL_TS" operator="A"><period value="24" unit="h"/></effectiveTime>')
            .$activity('<effectiveTime xsi:type="PIVL_TS" operator="A"><period value="24" unit="h"/></effectiveTime><routeCode code="C38216" codeSystem="2.16.840.1.113883.3.26.1.1" displayName="Synthetic inhaled"/>'),
        ), $patient);

        $rows = PhrMedication::query()->where('patient_id', $patient->id)->orderBy('id')->get();
        $this->assertSame([['every 12 h', null], ['every 24 h', null], ['every 24 h', 'Synthetic inhaled']], $rows->map(fn (PhrMedication $row): array => [$row->frequency, $row->route])->all());
    }

    /** A dose range is never stored as one of its bounds, as if it were exact. */
    #[DataProvider('doseRanges')]
    public function test_a_dose_range_keeps_its_bounds(string $bounds, string $dose, ?string $unit): void
    {
        $patient = $this->ccdaPatient();

        $this->importCcda($this->ccdaSectionDocument(<<<XML
            <templateId root="2.16.840.1.113883.10.20.22.2.1.1" extension="2014-06-09"/>
            <title>Medications</title>
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id root="2.25.5002" extension="med-range"/>
                <statusCode code="active"/>
                <doseQuantity xsi:type="IVL_PQ">{$bounds}</doseQuantity>
                <administrationUnitCode code="C48542" codeSystem="2.16.840.1.113883.3.26.1.1" displayName="Synthetic tablet"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9990090" codeSystem="2.16.840.1.113883.6.88" displayName="Synthetic ranged medicine"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML), $patient);

        $medication = PhrMedication::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame([$dose, $unit], [$medication->dose, $medication->dose_unit]);
    }

    protected static function model(): string
    {
        return PhrMedication::class;
    }

    protected static function fixture(): string
    {
        return 'medications.xml';
    }

    protected static function keyColumn(): string
    {
        return 'name';
    }

    protected static function entries(): array
    {
        return [
            'Synthetic tablet medicine 10 mg' => 'med-display',
            'Synthetic bedtime medicine' => 'med-original',
            'Synthetic translated medicine' => 'med-translation',
            'Synthetic named product' => 'med-name',
            'RxNorm|9990050' => 'med-code-only',
            // Nothing names it, so its own table row is read the way the
            // narrative-table reader reads it, rather than dropped.
            'Synthetic unnamed medicine' => 'med-unnamed',
        ];
    }

    protected static function expectedFields(): array
    {
        $none = array_fill_keys(array_values(self::entries()), null);

        return [
            'rxnorm_code' => [...$none,
                'med-display' => '9990010', 'med-original' => '9990020',
                // The code is null-flavored; its RxNorm translation carries it.
                'med-translation' => '9990030', 'med-code-only' => '9990050',
            ],
            'dose' => [...$none,
                'med-display' => '1', 'med-original' => '5',
                // By position, the narrative-table reader's dose is the sig.
                'med-unnamed' => 'Take 2 daily',
            ],
            'dose_unit' => [...$none,
                // UCUM's unity "1" is counted in the administration unit.
                'med-display' => 'Synthetic tablet', 'med-original' => 'mg',
            ],
            'route' => [...$none, 'med-display' => 'Synthetic oral'],
            'frequency' => [...$none, 'med-display' => 'every 12 h', 'med-original' => 'Synthetic bedtime'],
            'started_on' => [...$none,
                'med-display' => '2026-01-01', 'med-original' => '2026-01-01', 'med-translation' => '2026-01-01', 'med-name' => '2026-01-01',
            ],
            'ended_on' => [...$none, 'med-display' => '2026-03-01'],
            'status' => [
                'med-display' => 'completed', 'med-original' => 'active', 'med-translation' => 'discontinued',
                'med-name' => 'on_hold', 'med-code-only' => 'active', 'med-unnamed' => 'active',
            ],
            'reason_for_use' => [...$none, 'med-display' => 'Synthetic indication'],
            'raw_text' => [...$none,
                // The sig, by reference.
                'med-display' => 'Take 1 tablet by mouth every 12 hours',
                // A text that references the whole row reads the row.
                'med-translation' => 'Synthetic translated medicine Use as directed 01/01/2026 Discontinued',
                'med-name' => 'Synthetic named product 01/01/2026 On hold',
            ],
            'external_id' => [
                'med-display' => 'ccda-med-'.sha1('id|2.25.5001|med-display'),
                'med-original' => 'ccda-med-'.sha1('id|2.25.5001|med-original'),
                'med-translation' => 'ccda-med-'.sha1('id|2.25.5001|med-translation'),
                'med-name' => 'ccda-med-'.sha1('id|2.25.5001|med-name'),
                'med-code-only' => 'ccda-med-'.sha1('id|2.25.5001|med-code-only'),
                'med-unnamed' => 'ccda-med-'.sha1('Synthetic unnamed medicine|Take 2 daily|||||Active'),
            ],
        ];
    }

    protected static function negations(): array
    {
        return [
            // The negation's own text, when it has one.
            'Synthetic patient reports taking no medications' => [
                'assertion_type' => 'medication_absence', 'scope' => 'medications', 'observed_on' => '2026-01-02',
                'external_id' => 'ccda-negation-'.sha1('phr_medication|id|2.25.5001|med-negated'),
            ],
            'Not taking Synthetic declined medicine' => [
                'assertion_type' => 'medication_absence', 'scope' => 'medications', 'observed_on' => null,
                'external_id' => 'ccda-negation-'.sha1('phr_medication|id|2.25.5001|med-not-taking'),
            ],
        ];
    }

    protected static function exportedRecord(): array
    {
        return ['name' => 'Synthetic medication', 'dose' => '1 unit', 'frequency' => 'daily', 'status' => 'active'];
    }

    protected static function narrativeAdoption(): array
    {
        // Every activity but the code-only one references its row. The code-only
        // one and the two negations are added; the unnamed one reads its row
        // as before.
        return [
            'created' => 3,
            'updated' => 4,
            'unchanged' => 1,
            'narrative_key' => 'Synthetic tablet medicine 10 mg',
            'corrected_key' => 'Synthetic tablet medicine 10 mg',
        ];
    }

    protected static function earlierLoad(): array
    {
        return [
            'kind' => 'medication',
            'key' => '2.25.5001|med-display',
            'case_variant' => '2.25.5001|MED-DISPLAY',
            'external_id' => 'ccda-med-'.sha1('id|2.25.5001|med-display'),
        ];
    }

    protected static function earlierLoadColumns(): array
    {
        return ['name' => 'Synthetic tablet medicine 10 mg', 'status' => 'active'];
    }
}
