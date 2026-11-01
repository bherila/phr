<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Import\PhrCcdaImporter;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * PHR's own C-CDA export, imported again, keeps each reference range as bounds
 * with their inclusiveness, keeps text only where the source wrote it, and
 * updates the rows it already made instead of adding a second set.
 * Synthetic data only.
 */
final class PhrCcdaResultEntriesRoundTripTest extends TestCase
{
    public function test_a_reimport_stores_ranges_as_bounds_with_inclusiveness_not_as_text(): void
    {
        $source = $this->patient();
        $this->labs($source);

        $target = $this->patient();
        $result = app(PhrCcdaImporter::class)->importXml($this->export($source), $target, $target->owner_user_id);

        $this->assertSame(6, $result->created);
        $rows = PhrLabResult::query()->where('patient_id', $target->id)->get()->keyBy('analyte');

        // One-sided, inclusive: the bound and its inclusiveness, and no text.
        $this->assertRange($rows['Synthetic at most'], null, null, '5', true, 'mg/dL', null);
        // One-sided, exclusive.
        $this->assertRange($rows['Synthetic strictly above'], '60', false, null, null, 'mL/min', null);
        // Two-sided, unknown inclusiveness: the span, nothing claimed about its ends.
        $this->assertRange($rows['Synthetic span'], '13.6', null, '17.5', null, 'g/dL', null);
        // Two-sided with an exclusive bound: the case a text span cannot express.
        $this->assertRange($rows['Synthetic mixed'], '1', true, '9', false, 'mmol/L', null);
        // Text only: the source's words, no bounds.
        $this->assertRange($rows['Synthetic text'], null, null, null, null, null, 'Nonreactive');
        // Text the source wrote beside bounds keeps both.
        $this->assertRange($rows['Synthetic both'], null, null, '5', true, 'mg/dL', 'up to 5');

        // The result itself and its flag come back too.
        $this->assertSame(['5.2', 'mg/dL', 'H', 'Synthetic panel'], [$rows['Synthetic at most']->value, $rows['Synthetic at most']->unit, $rows['Synthetic at most']->abnormal_flag, $rows['Synthetic at most']->test_name]);
        $this->assertSame('2026-01-02', $rows['Synthetic at most']->result_datetime?->toDateString());
    }

    public function test_reimporting_the_same_export_changes_nothing_and_exporting_again_names_the_same_results(): void
    {
        $source = $this->patient();
        $this->labs($source);
        $xml = $this->export($source);
        $target = $this->patient();
        $importer = app(PhrCcdaImporter::class);
        $importer->importXml($xml, $target, $target->owner_user_id);
        $ids = $this->labIds($target);

        $again = $importer->importXml($xml, $target, $target->owner_user_id);

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 6], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged]);
        $this->assertSame($ids, $this->labIds($target), 'no duplicate rows');
        // The export of unchanged data names its results the same way, so a later import
        // updates these rows rather than adding others.
        $this->assertSame($this->observationIds($xml), $this->observationIds($this->export($source)));
        $this->assertCount(6, array_unique($this->observationIds($xml)));
    }

    public function test_a_document_first_imported_from_its_narrative_is_updated_in_place(): void
    {
        $source = $this->patient();
        $this->labs($source);
        $xml = $this->export($source);
        $target = $this->patient();
        $importer = app(PhrCcdaImporter::class);

        // What an importer saw before results were read from their entries.
        $importer->importXml($this->withoutEntries($xml), $target, $target->owner_user_id);
        $ids = $this->labIds($target);
        $this->assertCount(6, $ids);

        $result = $importer->importXml($xml, $target, $target->owner_user_id);

        $this->assertSame(0, $result->created);
        $this->assertSame($ids, $this->labIds($target), 'the same rows, no second set');
        $row = PhrLabResult::query()->where('patient_id', $target->id)->where('analyte', 'Synthetic mixed')->sole();
        $this->assertRange($row, '1', true, '9', false, 'mmol/L', null);
    }

    private function assertRange(PhrLabResult $row, ?string $min, ?bool $minInclusive, ?string $max, ?bool $maxInclusive, ?string $unit, ?string $text): void
    {
        // A bound is compared as a number (the column pads its decimals); inclusiveness
        // strictly, since null (unknown) is not false (exclusive).
        foreach ([[$min, $row->range_min], [$max, $row->range_max]] as [$expected, $actual]) {
            $expected === null ? $this->assertNull($actual, $row->analyte) : $this->assertEquals($expected, $actual, $row->analyte);
        }
        $this->assertSame(
            [$minInclusive, $maxInclusive, $unit, $text],
            [$row->range_min_inclusive, $row->range_max_inclusive, $row->range_unit, $row->reference_range_text],
            $row->analyte,
        );
    }

    /** @return list<string> */
    private function observationIds(string $xml): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $ids = [];
        foreach ((new DOMXPath($document))->query('//*[local-name()="observation"]/*[local-name()="id"]/@root') ?: [] as $root) {
            $ids[] = (string) $root->nodeValue;
        }

        return $ids;
    }

    private function labs(PhrPatient $patient): void
    {
        $when = ['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'test_name' => 'Synthetic panel', 'result_datetime' => '2026-01-02 08:30:00', 'value' => '5.2', 'unit' => 'mg/dL'];
        foreach ([
            ['analyte' => 'Synthetic at most', 'range_max' => '5', 'range_max_inclusive' => true, 'range_unit' => 'mg/dL', 'abnormal_flag' => 'H'],
            ['analyte' => 'Synthetic strictly above', 'range_min' => '60', 'range_min_inclusive' => false, 'range_unit' => 'mL/min'],
            ['analyte' => 'Synthetic span', 'range_min' => '13.6', 'range_max' => '17.5', 'range_unit' => 'g/dL'],
            ['analyte' => 'Synthetic mixed', 'range_min' => '1', 'range_min_inclusive' => true, 'range_max' => '9', 'range_max_inclusive' => false, 'range_unit' => 'mmol/L'],
            ['analyte' => 'Synthetic text', 'value' => 'Nonreactive', 'unit' => null, 'reference_range_text' => 'Nonreactive'],
            ['analyte' => 'Synthetic both', 'reference_range_text' => 'up to 5', 'range_max' => '5', 'range_max_inclusive' => true, 'range_unit' => 'mg/dL'],
        ] as $lab) {
            PhrLabResult::query()->create([...$when, ...$lab, 'review_status' => 'confirmed']);
        }
    }

    private function export(PhrPatient $patient): string
    {
        $data = ['patient' => $patient, 'lab_results' => PhrLabResult::query()->where('patient_id', $patient->id)->orderBy('id')->get()];
        foreach (['vitals', 'conditions', 'medications', 'procedures', 'immunizations', 'allergies', 'office_visits', 'portal_messages', 'negative_assertions', 'dicom_studies', 'documents'] as $key) {
            $data[$key] = collect();
        }

        return (new PhrCcdaExporter)->documentXml($data);
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

    /** @return list<int> */
    private function labIds(PhrPatient $patient): array
    {
        return PhrLabResult::query()->where('patient_id', $patient->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
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
