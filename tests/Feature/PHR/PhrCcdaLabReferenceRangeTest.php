<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Import\PhrCcdaImporter;
use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;

final class PhrCcdaLabReferenceRangeTest extends TestCase
{
    private const string FIXTURE = 'Fixtures/ccda/lab-reference-ranges.xml';

    /**
     * observation id extension => [reference_range_text, range_min, range_max, range_unit]
     *
     * @var array<string, array{0: string|null, 1: string|null, 2: string|null, 3: string|null}>
     */
    private const array EXPECTED_RANGES = [
        // <text> and an ST value holding the same string: taken once.
        'lab-st-neg' => ['NEG', null, null, null],
        'lab-st-detected' => ['Not Detected', null, null, null],
        // Only a unit, and nothing structured says so: kept once, as written.
        'lab-unit-only' => ['mIU/mL', null, null, null],
        'lab-ivl-ucum' => ['13.6 - 17.5', '13.6', '17.5', 'g/dL'],
        // The unit sits in each bound's translation/originalText; it goes to
        // range_unit and is never appended to the text again.
        'lab-ivl-translation' => ['4.3 - 5.6 PERCENT', '4.3', '5.6', 'PERCENT'],
        // No <text>: the bound and unit are the range; no text is made up from
        // them, so text never disagrees with the numbers.
        'lab-ivl-no-text' => [null, null, '200', 'mg/dL'],
        // <text> by reference resolves to the narrative it points at.
        'lab-text-reference' => ['Nonreactive', null, null, null],
        'lab-interval-value' => ['<150 mg/g', null, '150', 'mg/g'],
        'lab-ed-reference' => ['No pathogens', null, null, null],
    ];

    public function test_a_reference_range_is_stored_once_for_every_shape_that_doubled(): void
    {
        $patient = $this->patient();

        $result = app(PhrCcdaImporter::class)->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $this->assertSame(count(self::EXPECTED_RANGES), $result->created, 'one row per Result Observation; the narrative table is not read a second time');
        $rows = $this->rowsByObservation($patient);
        $this->assertSame(array_keys(self::EXPECTED_RANGES), array_keys($rows));
        foreach (self::EXPECTED_RANGES as $extension => [$text, $min, $max, $unit]) {
            $row = $rows[$extension];
            $this->assertSame($text, $row->reference_range_text, "{$extension} reference_range_text");
            $this->assertSame($min, $this->decimal($row->range_min), "{$extension} range_min");
            $this->assertSame($max, $this->decimal($row->range_max), "{$extension} range_max");
            $this->assertSame($unit, $row->range_unit, "{$extension} range_unit");
            // The fixture's numeric bounds that state it, "<200" and "<150", are exclusive.
            $this->assertSame(in_array($extension, ['lab-ivl-no-text', 'lab-interval-value'], true) ? [null, false] : [null, null], [$row->range_min_inclusive, $row->range_max_inclusive], "{$extension} inclusiveness");
            $this->assertDoesNotMatchRegularExpression('/^(.+)\1$/su', (string) $row->reference_range_text, $extension);
        }
    }

    public function test_result_values_come_from_the_observation_and_not_from_its_reference_range(): void
    {
        $patient = $this->patient();

        app(PhrCcdaImporter::class)->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $rows = $this->rowsByObservation($patient);
        $this->assertSame(['Synthetic panel', 'Synthetic screen', 'NEG', null, 'N'], [
            $rows['lab-st-neg']->test_name, $rows['lab-st-neg']->analyte, $rows['lab-st-neg']->value, $rows['lab-st-neg']->unit, $rows['lab-st-neg']->abnormal_flag,
        ]);
        // The observation has no interpretation of its own; the range's "N" is
        // the range's, not the result's.
        $this->assertNull($rows['lab-st-detected']->abnormal_flag);
        $this->assertSame('A', $rows['lab-text-reference']->abnormal_flag);
        $this->assertSame(['14.1', 'g/dL'], [$rows['lab-ivl-ucum']->value, $rows['lab-ivl-ucum']->unit]);
        $this->assertSame(['5.4', 'PERCENT'], [$rows['lab-ivl-translation']->value, $rows['lab-ivl-translation']->unit]);
        $this->assertSame(['<30', 'mg/g'], [$rows['lab-interval-value']->value, $rows['lab-interval-value']->unit]);
        $this->assertSame(['8', null], [$rows['lab-unit-only']->value, $rows['lab-unit-only']->unit]);
        // Value, range text and interpretation that are only narrative references.
        $this->assertSame(['Growth of normal flora', 'Abnormal'], [$rows['lab-ed-reference']->value, $rows['lab-ed-reference']->abnormal_flag]);
        $this->assertSame('2026-01-02 03:04:05', $rows['lab-ivl-ucum']->result_datetime?->format('Y-m-d H:i:s'));
    }

    public function test_reimporting_the_same_document_changes_nothing(): void
    {
        $patient = $this->patient();
        $importer = app(PhrCcdaImporter::class);

        $importer->importXml($this->fixture(), $patient, $patient->owner_user_id);
        $again = $importer->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $this->assertSame(['created' => 0, 'updated' => 0], ['created' => $again->created, 'updated' => $again->updated]);
        $this->assertSame(count(self::EXPECTED_RANGES), PhrLabResult::query()->where('patient_id', $patient->id)->count());
    }

    public function test_a_document_without_result_entries_still_imports_its_narrative_table(): void
    {
        $patient = $this->patient();

        // PHR's own export carries the results narrative but no Result Organizer.
        app(PhrCcdaImporter::class)->importXml(PhrCcdaSyntheticDocument::xml(), $patient, $patient->owner_user_id);

        $lab = PhrLabResult::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(['Synthetic analyte', '0 - 2'], [$lab->analyte, $lab->reference_range_text]);
    }

    public function test_an_exported_range_keeps_its_reference_unit_through_a_narrative_reimport(): void
    {
        $patient = $this->patient();
        $lab = new PhrLabResult([
            'analyte' => 'Synthetic exported analyte',
            'value' => '5.4',
            'unit' => 'mg/dL',
            'range_min' => '3.5',
            'range_max' => '5.1',
            'range_unit' => 'g/dL',
        ]);
        $lab->id = 1;
        $xml = (new PhrCcdaExporter)->documentXml([
            'patient' => $patient,
            'lab_results' => collect([$lab]),
            ...array_fill_keys(['vitals', 'conditions', 'medications', 'procedures', 'immunizations', 'allergies', 'office_visits', 'portal_messages', 'negative_assertions', 'dicom_studies', 'documents'], collect()),
        ]);

        // PHR's export has no Result Organizer, so this reads its narrative table.
        app(PhrCcdaImporter::class)->importXml($xml, $patient, $patient->owner_user_id);

        $row = PhrLabResult::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(['5.4', 'mg/dL', '3.5 - 5.1 g/dL'], [$row->value, $row->unit, $row->reference_range_text]);
    }

    private function fixture(): string
    {
        $xml = file_get_contents(base_path('tests/'.self::FIXTURE));
        $this->assertIsString($xml);

        return $xml;
    }

    /** @return array<string, PhrLabResult> */
    private function rowsByObservation(PhrPatient $patient): array
    {
        $rows = [];
        foreach (PhrLabResult::query()->where('patient_id', $patient->id)->orderBy('id')->get() as $row) {
            $rows[$this->extensionOf($row)] = $row;
        }

        return $rows;
    }

    private function extensionOf(PhrLabResult $row): string
    {
        // Rows are keyed by the analyte the fixture gives each observation.
        return match ($row->analyte) {
            'Synthetic screen' => 'lab-st-neg',
            'Synthetic organism' => 'lab-st-detected',
            'Synthetic hormone' => 'lab-unit-only',
            'Synthetic protein' => 'lab-ivl-ucum',
            'Synthetic fraction' => 'lab-ivl-translation',
            'Synthetic lipid' => 'lab-ivl-no-text',
            'Synthetic antibody' => 'lab-text-reference',
            'Synthetic ratio' => 'lab-interval-value',
            'Synthetic culture' => 'lab-ed-reference',
            default => 'unexpected: '.$row->analyte,
        };
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // decimal(18,10) columns read back padded; compare the number itself.
        $string = rtrim(rtrim((string) $value, '0'), '.');

        return $string === '' ? '0' : $string;
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
