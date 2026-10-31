<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrCcdaNarrativeRow;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * A Result Observation whose code names its test only by code is still a
 * result. The structured reader must never import fewer results for a section
 * than the narrative-table reader would have.
 */
final class PhrCcdaCodeOnlyObservationTest extends TestCase
{
    private const string FIXTURE = 'Fixtures/ccda/lab-code-only.xml';

    public function test_a_code_only_observation_is_named_by_the_narrative_its_text_references(): void
    {
        $rows = $this->importFixture();

        $this->assertArrayHasKey('Synthetic referenced analyte', $rows);
        $this->assertSame('NEG', $rows['Synthetic referenced analyte']->value);
    }

    public function test_a_code_only_observation_with_no_text_keeps_its_code_as_its_name(): void
    {
        $rows = $this->importFixture();

        $this->assertArrayHasKey('SYN|SYN-22', $rows);
        $this->assertSame(['3', 'mg/dL'], [$rows['SYN|SYN-22']->value, $rows['SYN|SYN-22']->unit]);
    }

    public function test_a_code_only_coded_result_keeps_its_code_as_its_value(): void
    {
        $rows = $this->importFixture();

        $this->assertSame('POS', $rows['Synthetic coded result']->value);
    }

    public function test_readable_and_code_only_observations_in_one_section_are_all_imported(): void
    {
        $rows = $this->importFixture();

        foreach (['Synthetic readable', 'Synthetic referenced analyte', 'SYN|SYN-22', 'Synthetic coded result'] as $analyte) {
            $this->assertArrayHasKey($analyte, $rows);
            $this->assertSame('ccda-lab-', substr((string) $rows[$analyte]->external_id, 0, 9));
        }
    }

    public function test_an_observation_nothing_names_falls_back_to_its_own_narrative_row(): void
    {
        $patient = $this->patient();

        app(PhrCcdaImporter::class)->importXml($this->xml(self::FIXTURE), $patient, $patient->owner_user_id);

        // Read as the narrative-table reader reads that row, under the same id,
        // rather than dropped.
        $narrativeId = PhrCcdaNarrativeRow::labExternalId(['Synthetic unnamed', '12', '', '', '01/02/2026 3:04 AM PST']);
        $this->assertTrue(PhrLabResult::query()->where('patient_id', $patient->id)->where('external_id', $narrativeId)->exists());
        $this->assertSame(5, PhrLabResult::query()->where('patient_id', $patient->id)->count());
    }

    public function test_the_structured_reader_imports_at_least_as_many_results_as_the_narrative_reader(): void
    {
        foreach ([self::FIXTURE, 'Fixtures/ccda/lab-reference-ranges.xml'] as $fixture) {
            $narrative = $this->patient();
            $structured = $this->patient();
            $importer = app(PhrCcdaImporter::class);

            $importer->importXml($this->withoutEntries($this->xml($fixture)), $narrative, $narrative->owner_user_id);
            $importer->importXml($this->xml($fixture), $structured, $structured->owner_user_id);

            $this->assertGreaterThanOrEqual(
                PhrLabResult::query()->where('patient_id', $narrative->id)->count(),
                PhrLabResult::query()->where('patient_id', $structured->id)->count(),
                $fixture,
            );
        }
    }

    /** @return array<string, PhrLabResult> */
    private function importFixture(): array
    {
        $patient = $this->patient();
        app(PhrCcdaImporter::class)->importXml($this->xml(self::FIXTURE), $patient, $patient->owner_user_id);

        $rows = [];
        foreach (PhrLabResult::query()->where('patient_id', $patient->id)->get() as $row) {
            $rows[(string) $row->analyte] = $row;
        }

        return $rows;
    }

    private function xml(string $fixture): string
    {
        $xml = file_get_contents(base_path('tests/'.$fixture));
        $this->assertIsString($xml);

        return $xml;
    }

    private function withoutEntries(string $xml): string
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        foreach (iterator_to_array((new DOMXPath($document))->query('//*[local-name()="entry"]') ?: []) as $entry) {
            $entry->parentNode?->removeChild($entry);
        }

        return (string) $document->saveXML();
    }

    private function patient(): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $this->createUser()->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);
    }
}
