<?php

namespace Tests\Unit\Services\PHR;

use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Export\PhrPdfSummaryRenderer;
use DOMDocument;
use DOMXPath;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * Structured immunization doses (dose_amount + dose_unit) must survive every clinical
 * export representation. Synthetic data only.
 */
class PhrImmunizationDoseExportTest extends TestCase
{
    public function test_fhir_emits_dose_quantity_with_exact_decimal_and_ucum_only_for_known_units(): void
    {
        $json = (new PhrFhirExporter)->bundleJson($this->exportData([
            $this->immunization(1, ['dose_amount' => '0.50', 'dose_unit' => 'mL', 'dose_number' => 2]),
            $this->immunization(2, ['dose_amount' => '1.250', 'dose_unit' => 'Synthetic puff']),
            $this->immunization(3),
        ]));

        // The literal decimal text is preserved (FHIR decimals keep their written precision).
        $this->assertStringContainsString('"value": 0.5', $json);
        $this->assertStringContainsString('"value": 1.25', $json);
        $this->assertStringNotContainsString('phr-decimal-', $json);

        $resources = $this->fhirImmunizations($json);

        $this->assertSame([
            'value' => 0.5,
            'unit' => 'mL',
            'system' => 'http://unitsofmeasure.org',
            'code' => 'mL',
        ], $resources['phr-immunization-1']['doseQuantity']);
        // doseQuantity is the administered amount; the series position is not emitted from it.
        $this->assertArrayNotHasKey('protocolApplied', $resources['phr-immunization-1']);

        $this->assertSame([
            'value' => 1.25,
            'unit' => 'Synthetic puff',
        ], $resources['phr-immunization-2']['doseQuantity']);

        $this->assertArrayNotHasKey('doseQuantity', $resources['phr-immunization-3']);
        $this->assertSame([
            'resourceType' => 'Immunization',
            'id' => 'phr-immunization-3',
            'status' => 'completed',
            'patient' => ['reference' => 'Patient/phr-patient-42'],
            'vaccineCode' => ['text' => 'Synthetic vaccine 3'],
            'occurrenceDateTime' => '2025-10-01',
            'lotNumber' => 'SYNLOT3',
        ], $resources['phr-immunization-3']);
    }

    public function test_fhir_omits_dose_quantity_for_invalid_amounts_and_canonicalizes_json_number_grammar(): void
    {
        $json = (new PhrFhirExporter)->bundleJson($this->exportData([
            $this->immunization(1, ['dose_amount' => '0.5 mL', 'dose_unit' => 'mL']),
            $this->immunization(2, ['dose_amount' => '-1', 'dose_unit' => 'mL']),
            $this->immunization(3, ['dose_amount' => null, 'dose_unit' => 'mL']),
            $this->immunization(4, ['dose_amount' => '.5', 'dose_unit' => 'mcg']),
            $this->immunization(5, ['dose_amount' => '007.10', 'dose_unit' => null]),
        ]));

        $resources = $this->fhirImmunizations($json);

        $this->assertArrayNotHasKey('doseQuantity', $resources['phr-immunization-1']);
        $this->assertArrayNotHasKey('doseQuantity', $resources['phr-immunization-2']);
        $this->assertArrayNotHasKey('doseQuantity', $resources['phr-immunization-3']);
        $this->assertSame(['value' => 0.5, 'unit' => 'mcg', 'system' => 'http://unitsofmeasure.org', 'code' => 'ug'], $resources['phr-immunization-4']['doseQuantity']);
        $this->assertSame(['value' => 7.1], $resources['phr-immunization-5']['doseQuantity']);
        $this->assertStringContainsString('"value": 0.5', $json);
        $this->assertStringContainsString('"value": 7.1', $json);
    }

    public function test_fhir_status_stays_completed_and_partial_administration_is_marked_subpotent(): void
    {
        $resources = $this->fhirImmunizations((new PhrFhirExporter)->bundleJson($this->exportData([
            $this->immunization(1, ['administration_status' => 'given']),
            $this->immunization(2, ['administration_status' => 'historical']),
            $this->immunization(3, ['administration_status' => 'partial']),
        ])));

        foreach ($resources as $resource) {
            $this->assertSame('completed', $resource['status']);
        }
        $this->assertArrayNotHasKey('isSubpotent', $resources['phr-immunization-1']);
        $this->assertArrayNotHasKey('isSubpotent', $resources['phr-immunization-2']);
        $this->assertTrue($resources['phr-immunization-3']['isSubpotent']);
        $this->assertSame([[
            'coding' => [[
                'system' => 'http://terminology.hl7.org/CodeSystem/immunization-subpotent-reason',
                'code' => 'partial',
                'display' => 'Partial Dose',
            ]],
        ]], $resources['phr-immunization-3']['subpotentReason']);
    }

    public function test_ccda_immunization_table_adds_a_dose_column_only_when_a_dose_was_recorded(): void
    {
        $rows = $this->ccdaImmunizationTable([
            $this->immunization(1, ['dose_amount' => '0.50', 'dose_unit' => 'mL']),
            $this->immunization(2, ['dose_amount' => '1.250', 'dose_unit' => 'Synthetic puff']),
            $this->immunization(3),
        ]);

        $this->assertSame([
            ['Vaccine', 'Date', 'Lot', 'Dose'],
            ['Synthetic vaccine 1', '2025-10-01', 'SYNLOT1', '0.50 mL'],
            ['Synthetic vaccine 2', '2025-10-01', 'SYNLOT2', '1.250 Synthetic puff'],
            ['Synthetic vaccine 3', '2025-10-01', 'SYNLOT3', ''],
        ], $rows);
    }

    public function test_ccda_immunization_table_without_doses_keeps_the_established_columns(): void
    {
        $rows = $this->ccdaImmunizationTable([
            $this->immunization(3),
            $this->immunization(4, ['dose_amount' => null, 'dose_unit' => 'mL']),
        ]);

        $this->assertSame([
            ['Vaccine', 'Date', 'Lot'],
            ['Synthetic vaccine 3', '2025-10-01', 'SYNLOT3'],
            ['Synthetic vaccine 4', '2025-10-01', 'SYNLOT4'],
        ], $rows);
    }

    public function test_pdf_summary_shows_dose_amount_and_unit_alongside_existing_fields(): void
    {
        $pdf = (new PhrPdfSummaryRenderer)->render($this->exportData([
            $this->immunization(1, ['dose_amount' => '0.50', 'dose_unit' => 'mL']),
            $this->immunization(2, ['dose_amount' => '1.250', 'dose_unit' => 'Synthetic puff']),
            $this->immunization(3),
        ]));
        $text = preg_replace('/\s+/', ' ', (new Parser)->parseContent($pdf)->getText()) ?? '';

        $this->assertStringContainsString('2025-10-01 | Synthetic vaccine 1 | SYNLOT1 | 0.50 mL', $text);
        $this->assertStringContainsString('2025-10-01 | Synthetic vaccine 2 | SYNLOT2 | 1.250 Synthetic puff', $text);
        $this->assertMatchesRegularExpression('/2025-10-01 \| Synthetic vaccine 3 \| SYNLOT3(?! \|)/', $text);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function immunization(int $id, array $attributes = []): PhrImmunization
    {
        $immunization = new PhrImmunization(array_merge([
            'patient_id' => 42,
            'vaccine_name' => 'Synthetic vaccine '.$id,
            'administered_on' => '2025-10-01',
            'lot_number' => 'SYNLOT'.$id,
        ], $attributes));
        $immunization->id = $id;

        return $immunization;
    }

    /**
     * @param  array<int, PhrImmunization>  $immunizations
     * @return array<string, mixed>
     */
    private function exportData(array $immunizations): array
    {
        $patient = new PhrPatient(['display_name' => 'Synthetic Patient']);
        $patient->id = 42;

        return [
            'patient' => $patient,
            'lab_results' => collect(),
            'vitals' => collect(),
            'conditions' => collect(),
            'medications' => collect(),
            'procedures' => collect(),
            'immunizations' => collect($immunizations),
            'allergies' => collect(),
            'office_visits' => collect(),
            'portal_messages' => collect(),
            'negative_assertions' => collect(),
            'dicom_studies' => collect(),
            'documents' => collect(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fhirImmunizations(string $json): array
    {
        /** @var array{entry: array<int, array{resource: array<string, mixed>}>} $bundle */
        $bundle = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $resources = [];
        foreach ($bundle['entry'] as $entry) {
            if ($entry['resource']['resourceType'] === 'Immunization') {
                $resources[(string) $entry['resource']['id']] = $entry['resource'];
            }
        }

        return $resources;
    }

    /**
     * @param  array<int, PhrImmunization>  $immunizations
     * @return array<int, array<int, string>>
     */
    private function ccdaImmunizationTable(array $immunizations): array
    {
        $document = new DOMDocument;
        $document->loadXML((new PhrCcdaExporter)->documentXml($this->exportData($immunizations)));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('h', 'urn:hl7-org:v3');

        $rows = [];
        $rowNodes = $xpath->query("//h:section[h:title='Immunizations']/h:text/h:table//h:tr");
        $this->assertNotFalse($rowNodes);
        foreach ($rowNodes as $rowNode) {
            $cells = [];
            $cellNodes = $xpath->query('h:th|h:td', $rowNode);
            $this->assertNotFalse($cellNodes);
            foreach ($cellNodes as $cell) {
                $cells[] = $cell->textContent;
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
