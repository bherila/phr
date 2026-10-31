<?php

namespace Tests\Unit\Services\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
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
            $this->lab(1, 'Synthetic both', ['range_min' => '13.6000000000', 'range_max' => '17.5000000000']),
            $this->lab(2, 'Synthetic upper', ['range_max' => '200.0000000000']),
            $this->lab(3, 'Synthetic lower', ['range_min' => '40.0000000000']),
            $this->lab(4, 'Synthetic text', ['range_max' => '165.0000000000', 'reference_range_text' => '<165 IU/mL']),
            $this->lab(5, 'Synthetic none', []),
        ]));

        $this->assertSame([
            'Synthetic both' => '13.6 - 17.5',
            'Synthetic upper' => '<200',
            'Synthetic lower' => '>40',
            // Text the source wrote is exported as written.
            'Synthetic text' => '<165 IU/mL',
            'Synthetic none' => '',
        ], $this->narrativeRanges($xml));
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
     * @param  array<string, string>  $attributes
     */
    private function lab(int $id, string $analyte, array $attributes): PhrLabResult
    {
        $lab = new PhrLabResult([
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
