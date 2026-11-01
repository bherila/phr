<?php

namespace Tests\Unit\Services\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrFhirExporter;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * A reference range is its numeric bounds and unit, with text only where the
 * source wrote one. Exports render a range without text from its bounds,
 * one-sided included. Synthetic data only.
 */
class PhrLabReferenceRangeExportTest extends TestCase
{
    public function test_the_ccda_narrative_renders_a_range_without_text_from_its_bounds(): void
    {
        $xml = (new PhrCcdaExporter)->documentXml($this->exportData([
            // The result's unit is mg/dL; the reference unit differs, and the
            // table's Unit column holds only the result's.
            $this->lab(1, 'Synthetic both', ['range_min' => '13.6000000000', 'range_max' => '17.5000000000', 'range_unit' => 'g/dL']),
            $this->lab(2, 'Synthetic upper', ['range_max' => '200.0000000000', 'range_unit' => 'mg/dL']),
            $this->lab(3, 'Synthetic lower', ['range_min' => '40.0000000000', 'range_unit' => 'mmol/L']),
            $this->lab(4, 'Synthetic text', ['range_max' => '165.0000000000', 'range_unit' => 'IU/mL', 'reference_range_text' => '<165 IU/mL']),
            $this->lab(5, 'Synthetic none', ['range_unit' => 'g/dL']),
            $this->lab(6, 'Synthetic unitless', ['range_min' => '4', 'range_max' => '14']),
            // A bound the source called inclusive or exclusive reads that way.
            $this->lab(7, 'Synthetic at most', ['range_max' => '5.0000000000', 'range_max_inclusive' => true, 'range_unit' => 'mg/dL']),
            $this->lab(8, 'Synthetic at least', ['range_min' => '60.0000000000', 'range_min_inclusive' => true, 'range_unit' => 'mL/min']),
            $this->lab(9, 'Synthetic strictly below', ['range_max' => '5.0000000000', 'range_max_inclusive' => false, 'range_unit' => 'mg/dL']),
            $this->lab(10, 'Synthetic strictly above', ['range_min' => '60.0000000000', 'range_min_inclusive' => false]),
            // Two bounds read as a span either way.
            $this->lab(11, 'Synthetic inclusive span', ['range_min' => '1', 'range_min_inclusive' => true, 'range_max' => '9', 'range_max_inclusive' => false]),
        ]));

        $this->assertSame([
            'Synthetic both' => '13.6 - 17.5 g/dL',
            'Synthetic upper' => '<200 mg/dL',
            'Synthetic lower' => '>40 mmol/L',
            // Text the source wrote is exported as written.
            'Synthetic text' => '<165 IU/mL',
            'Synthetic none' => '',
            'Synthetic unitless' => '4 - 14',
            'Synthetic at most' => '<=5 mg/dL',
            'Synthetic at least' => '>=60 mL/min',
            'Synthetic strictly below' => '<5 mg/dL',
            'Synthetic strictly above' => '>60',
            'Synthetic inclusive span' => '1 - 9',
        ], $this->narrativeRanges($xml));
    }

    public function test_fhir_writes_a_single_bound_whose_inclusiveness_is_known_as_text_too(): void
    {
        $json = (new PhrFhirExporter)->bundleJson($this->exportData([
            $this->lab(1, 'Synthetic at most', ['range_max' => '5.0000000000', 'range_max_inclusive' => true, 'range_unit' => 'mg/dL']),
            $this->lab(2, 'Synthetic strictly above', ['range_min' => '60.0000000000', 'range_min_inclusive' => false, 'range_unit' => 'mL/min']),
            // Unknown, two-sided, or with source text: exported as before.
            $this->lab(3, 'Synthetic unstated', ['range_max' => '5', 'range_unit' => 'mg/dL']),
            $this->lab(4, 'Synthetic span', ['range_min' => '1', 'range_min_inclusive' => false, 'range_max' => '9', 'range_max_inclusive' => true]),
            $this->lab(5, 'Synthetic own text', ['range_max' => '5', 'range_max_inclusive' => true, 'reference_range_text' => 'up to 5']),
        ]));

        $ranges = [];
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['entry'] as $entry) {
            if (($entry['resource']['resourceType'] ?? null) === 'Observation') {
                $range = $entry['resource']['referenceRange'][0];
                $ranges[$entry['resource']['code']['text']] = [$range['low']['value'] ?? null, $range['high']['value'] ?? null, $range['text'] ?? null];
            }
        }

        $this->assertEquals([
            'Synthetic at most' => [null, 5, '<=5 mg/dL'],
            'Synthetic strictly above' => [60, null, '>60 mL/min'],
            'Synthetic unstated' => [null, 5, null],
            'Synthetic span' => [1, 9, null],
            'Synthetic own text' => [null, 5, 'up to 5'],
        ], $ranges);
    }

    /**
     * @return array<string, string>
     */
    private function narrativeRanges(string $xml): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);

        $ranges = [];
        foreach ($xpath->query('//*[local-name()="section"][*[local-name()="title"]="Results"]//*[local-name()="tbody"]/*[local-name()="tr"]') ?: [] as $tr) {
            $cells = [];
            foreach ($xpath->query('./*[local-name()="td"]', $tr) ?: [] as $td) {
                $cells[] = trim($td->textContent);
            }
            $ranges[$cells[1]] = $cells[4];
        }

        return $ranges;
    }

    /**
     * @param  array<string, string|bool>  $attributes
     */
    private function lab(int $id, string $analyte, array $attributes): PhrLabResult
    {
        $lab = new PhrLabResult([
            'patient_id' => 42,
            'analyte' => $analyte,
            'value' => '1',
            'unit' => 'mg/dL',
            ...$attributes,
        ]);
        $lab->id = $id;

        return $lab;
    }

    /**
     * @param  list<PhrLabResult>  $labs
     * @return array<string, mixed>
     */
    private function exportData(array $labs): array
    {
        $patient = new PhrPatient(['display_name' => 'Synthetic Patient']);
        $patient->id = 42;

        return [
            'patient' => $patient,
            'lab_results' => collect($labs),
            'vitals' => collect(),
            'conditions' => collect(),
            'medications' => collect(),
            'procedures' => collect(),
            'immunizations' => collect(),
            'allergies' => collect(),
            'office_visits' => collect(),
            'portal_messages' => collect(),
            'negative_assertions' => collect(),
            'dicom_studies' => collect(),
            'documents' => collect(),
        ];
    }
}
