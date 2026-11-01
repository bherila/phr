<?php

namespace Tests\Unit\Services\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Results section's Result Organizer / Result Observation entries beside the
 * narrative table. Synthetic data only.
 */
class PhrCcdaResultEntriesTest extends TestCase
{
    private const string ORGANIZER = '2.16.840.1.113883.10.20.22.4.1';

    private const string OBSERVATION = '2.16.840.1.113883.10.20.22.4.2';

    public function test_each_lab_is_a_result_observation_in_a_result_organizer_that_references_its_narrative_row(): void
    {
        [$xpath] = $this->export([
            $this->lab(1, 'Synthetic sodium', ['test_name' => 'Synthetic panel', 'result_datetime' => Carbon::parse('2026-01-02 08:30:00 UTC')]),
            $this->lab(2, 'Synthetic potassium', ['test_name' => 'Synthetic panel', 'result_datetime' => Carbon::parse('2026-01-02 08:30:00 UTC')]),
            $this->lab(3, 'Synthetic lipid', ['test_name' => 'Synthetic lipid panel', 'result_datetime' => Carbon::parse('2026-01-02 08:30:00 UTC')]),
        ]);

        $organizers = $this->elements($xpath, '//c:section[c:title="Results"]/c:entry/c:organizer');
        // Labs of one test name and moment share an organizer.
        $this->assertCount(2, $organizers);
        foreach ($organizers as $organizer) {
            $this->assertSame('DRIV', $this->one($xpath, '../@typeCode', $organizer)->nodeValue);
            $this->assertSame(['BATTERY', 'EVN'], [$organizer->getAttribute('classCode'), $organizer->getAttribute('moodCode')]);
            $this->assertSame('2023-05-01', $this->one($xpath, 'c:templateId[@root="'.self::ORGANIZER.'"]/@extension', $organizer)->nodeValue);
            $this->assertNotNull($this->one($xpath, 'c:id/@root', $organizer));
            $this->assertSame('completed', $this->one($xpath, 'c:statusCode/@code', $organizer)->nodeValue);
        }
        $this->assertSame('Synthetic panel', $this->one($xpath, 'c:code/c:originalText', $organizers[0])->textContent);
        $this->assertCount(2, $this->elements($xpath, 'c:component/c:observation', $organizers[0]));

        $observations = $this->elements($xpath, '//c:organizer/c:component/c:observation');
        $this->assertCount(3, $observations);
        $ids = [];
        foreach ($observations as $i => $observation) {
            $this->assertSame(['OBS', 'EVN'], [$observation->getAttribute('classCode'), $observation->getAttribute('moodCode')]);
            $this->assertSame('2023-05-01', $this->one($xpath, 'c:templateId[@root="'.self::OBSERVATION.'"]/@extension', $observation)->nodeValue);
            $ids[] = $this->one($xpath, 'c:id/@root', $observation)->nodeValue;

            // The text references the narrative row of the same lab.
            $reference = ltrim((string) $this->one($xpath, 'c:text/c:reference/@value', $observation)->nodeValue, '#');
            $row = $this->el($xpath, '//c:tr[@ID="'.$reference.'"]');
            $this->assertSame(['Synthetic sodium', 'Synthetic potassium', 'Synthetic lipid'][$i], $this->one($xpath, 'c:td[2]', $row)->textContent);
            $this->assertSame(['Synthetic sodium', 'Synthetic potassium', 'Synthetic lipid'][$i], $this->one($xpath, 'c:code/c:originalText', $observation)->textContent);
            // No LOINC is stored, so the code is null-flavored rather than invented.
            $this->assertSame('UNK', $this->one($xpath, 'c:code/@nullFlavor', $observation)->nodeValue);
            $this->assertSame('20260102083000+0000', $this->one($xpath, 'c:effectiveTime/@value', $observation)->nodeValue);
        }
        $this->assertCount(3, array_unique($ids));
    }

    public function test_ids_are_stable_across_exports_and_differ_per_lab(): void
    {
        $labs = [$this->lab(1, 'Synthetic a', []), $this->lab(2, 'Synthetic b', [])];
        $first = $this->observationIds($this->export($labs)[0]);
        $second = $this->observationIds($this->export($labs)[0]);

        $this->assertSame($first, $second);
        $this->assertCount(2, array_unique($first));
        // Not the database key, in the clear.
        $this->assertStringNotContainsString('42', implode('', $first));
    }

    public function test_values_are_quantities_comparisons_or_strings(): void
    {
        [$xpath] = $this->export([
            $this->lab(1, 'Synthetic number', ['value' => '5.20', 'unit' => 'mg/dL']),
            $this->lab(2, 'Synthetic below', ['value' => '<5', 'unit' => 'mg/dL']),
            $this->lab(3, 'Synthetic at least', ['value' => '>=60', 'unit' => 'mL/min']),
            $this->lab(4, 'Synthetic qualitative', ['value' => 'Negative', 'unit' => null]),
            // A number with no unit is not given an invented one.
            $this->lab(5, 'Synthetic unitless', ['value' => '7', 'unit' => null]),
            $this->lab(6, 'Synthetic odd unit', ['value' => '5.2', 'unit' => 'thousand per uL']),
            $this->lab(7, 'Synthetic numeric only', ['value' => null, 'value_numeric' => '3.1000000000', 'unit' => 'g/L']),
        ]);

        $value = fn (string $analyte): DOMElement => $this->el($xpath, '//c:observation[c:code/c:originalText="'.$analyte.'"]/c:value');
        $number = $value('Synthetic number');
        $this->assertSame(['PQ', '5.20', 'mg/dL'], [$number->getAttribute('xsi:type'), $number->getAttribute('value'), $number->getAttribute('unit')]);

        $below = $value('Synthetic below');
        $this->assertSame('IVL_PQ', $below->getAttribute('xsi:type'));
        $this->assertCount(0, $this->elements($xpath, 'c:low', $below));
        $high = $this->el($xpath, 'c:high', $below);
        $this->assertSame(['5', 'mg/dL', 'false'], [$high->getAttribute('value'), $high->getAttribute('unit'), $high->getAttribute('inclusive')]);

        $low = $this->el($xpath, 'c:low', $value('Synthetic at least'));
        $this->assertSame(['60', 'mL/min', 'true'], [$low->getAttribute('value'), $low->getAttribute('unit'), $low->getAttribute('inclusive')]);

        $qualitative = $value('Synthetic qualitative');
        $this->assertSame(['ST', 'Negative'], [$qualitative->getAttribute('xsi:type'), $qualitative->textContent]);
        $unitless = $value('Synthetic unitless');
        $this->assertSame(['ST', '7'], [$unitless->getAttribute('xsi:type'), $unitless->textContent]);

        // A unit with whitespace cannot be a PQ's @unit: the number and the unit as written
        // travel in the translation.
        $odd = $value('Synthetic odd unit');
        $this->assertSame(['PQ', 'OTH', false], [$odd->getAttribute('xsi:type'), $odd->getAttribute('nullFlavor'), $odd->hasAttribute('unit')]);
        $this->assertSame('5.2', $this->one($xpath, 'c:translation/@value', $odd)->nodeValue);
        $this->assertSame('thousand per uL', $this->one($xpath, 'c:translation/c:originalText', $odd)->textContent);

        $numeric = $value('Synthetic numeric only');
        $this->assertSame(['PQ', '3.1'], [$numeric->getAttribute('xsi:type'), $numeric->getAttribute('value')]);
    }

    public function test_a_one_sided_range_carries_one_bound_with_its_inclusiveness(): void
    {
        [$xpath] = $this->export([
            $this->lab(1, 'Synthetic at most', ['range_max' => '5.0000000000', 'range_max_inclusive' => true, 'range_unit' => 'mg/dL']),
            $this->lab(2, 'Synthetic strictly above', ['range_min' => '60.0000000000', 'range_min_inclusive' => false, 'range_unit' => 'mL/min']),
            // Not stated by the source: no attribute, rather than HL7's default of inclusive.
            $this->lab(3, 'Synthetic unstated', ['range_max' => '200', 'range_unit' => 'mg/dL']),
        ]);

        $atMost = $this->range($xpath, 'Synthetic at most');
        $this->assertNull($atMost['text']);
        $this->assertSame(['low' => null, 'high' => ['5', 'mg/dL', 'true']], $atMost['bounds']);

        $above = $this->range($xpath, 'Synthetic strictly above');
        $this->assertSame(['low' => ['60', 'mL/min', 'false'], 'high' => null], $above['bounds']);

        $unstated = $this->range($xpath, 'Synthetic unstated');
        $this->assertSame(['low' => null, 'high' => ['200', 'mg/dL', null]], $unstated['bounds']);
    }

    public function test_a_two_sided_range_carries_both_bounds_including_an_exclusive_one(): void
    {
        [$xpath] = $this->export([
            $this->lab(1, 'Synthetic span', ['range_min' => '13.6000000000', 'range_max' => '17.5000000000', 'range_unit' => 'g/dL']),
            $this->lab(2, 'Synthetic mixed', ['range_min' => '1', 'range_min_inclusive' => true, 'range_max' => '9', 'range_max_inclusive' => false, 'range_unit' => 'mmol/L']),
            $this->lab(3, 'Synthetic unitless', ['range_min' => '4', 'range_max' => '14']),
        ]);

        $this->assertSame(['low' => ['13.6', 'g/dL', null], 'high' => ['17.5', 'g/dL', null]], $this->range($xpath, 'Synthetic span')['bounds']);
        // Exclusive on one side only: a plain span could not say so.
        $this->assertSame(['low' => ['1', 'mmol/L', 'true'], 'high' => ['9', 'mmol/L', 'false']], $this->range($xpath, 'Synthetic mixed')['bounds']);
        $this->assertSame(['low' => ['4', null, null], 'high' => ['14', null, null]], $this->range($xpath, 'Synthetic unitless')['bounds']);
    }

    public function test_a_text_only_range_keeps_the_source_text_and_an_unknown_structured_range(): void
    {
        [$xpath] = $this->export([
            $this->lab(1, 'Synthetic text', ['reference_range_text' => 'Nonreactive']),
            $this->lab(2, 'Synthetic both', ['reference_range_text' => 'up to 5', 'range_max' => '5', 'range_max_inclusive' => true, 'range_unit' => 'mg/dL']),
            $this->lab(3, 'Synthetic none', []),
        ]);

        $text = $this->range($xpath, 'Synthetic text');
        $this->assertSame('Nonreactive', $text['text']);
        $this->assertSame(['low' => null, 'high' => null], $text['bounds']);
        $this->assertSame('NI', $this->one($xpath, '//c:observation[c:code/c:originalText="Synthetic text"]//c:observationRange/c:value/@nullFlavor')->nodeValue);

        $both = $this->range($xpath, 'Synthetic both');
        $this->assertSame('up to 5', $both['text']);
        $this->assertSame(['low' => null, 'high' => ['5', 'mg/dL', 'true']], $both['bounds']);

        $this->assertCount(0, $this->nodes($xpath, '//c:observation[c:code/c:originalText="Synthetic none"]/c:referenceRange'));
        // The narrative Reference cell is unchanged.
        $this->assertSame('', $this->one($xpath, '//c:tr[c:td[2]="Synthetic none"]/c:td[5]')->textContent);
    }

    public function test_interpretation_status_and_time_use_the_value_sets(): void
    {
        [$xpath] = $this->export([
            $this->lab(1, 'Synthetic high', ['abnormal_flag' => 'h', 'result_status' => 'final', 'result_datetime' => Carbon::parse('2026-01-02 00:00:00')]),
            $this->lab(2, 'Synthetic words', ['abnormal_flag' => 'Out of range', 'result_status' => 'preliminary']),
            $this->lab(3, 'Synthetic cancelled', ['result_status' => 'entered-in-error']),
            $this->lab(4, 'Synthetic unknown status', ['result_status' => 'something else']),
        ]);

        $interpretation = fn (string $analyte): DOMElement => $this->el($xpath, '//c:observation[c:code/c:originalText="'.$analyte.'"]/c:interpretationCode');
        $high = $interpretation('Synthetic high');
        $this->assertSame(['H', '2.16.840.1.113883.5.83'], [$high->getAttribute('code'), $high->getAttribute('codeSystem')]);
        // Free text stays as written and is not guessed into a code.
        $words = $interpretation('Synthetic words');
        $this->assertSame(['OTH', 'Out of range', false], [$words->getAttribute('nullFlavor'), $this->el($xpath, 'c:originalText', $words)->textContent, $words->hasAttribute('code')]);
        $this->assertCount(0, $this->nodes($xpath, '//c:observation[c:code/c:originalText="Synthetic cancelled"]/c:interpretationCode'));

        // Result Status: aborted active cancelled completed held suspended.
        foreach ($this->nodes($xpath, '//c:statusCode/@code') as $code) {
            $this->assertContains($code->nodeValue, ['aborted', 'active', 'cancelled', 'completed', 'held', 'suspended']);
        }
        $status = fn (string $analyte): string => (string) $this->one($xpath, '//c:observation[c:code/c:originalText="'.$analyte.'"]/c:statusCode/@code')->nodeValue;
        $this->assertSame(['completed', 'active', 'cancelled', 'completed'], [$status('Synthetic high'), $status('Synthetic words'), $status('Synthetic cancelled'), $status('Synthetic unknown status')]);

        // A date at midnight has no recorded time of day; none is a null-flavored time.
        $this->assertSame('20260102', $this->one($xpath, '//c:observation[c:code/c:originalText="Synthetic high"]/c:effectiveTime/@value')->nodeValue);
        $none = $this->el($xpath, '//c:observation[c:code/c:originalText="Synthetic words"]/c:effectiveTime');
        $this->assertSame(['UNK', false], [$none->getAttribute('nullFlavor'), $none->hasAttribute('value')]);
    }

    public function test_an_export_without_labs_has_an_empty_null_flavored_results_section_with_no_entries(): void
    {
        [$xpath] = $this->export([]);

        $this->assertSame('NI', $this->one($xpath, '//c:section[c:title="Results"]/@nullFlavor')->nodeValue);
        $this->assertCount(0, $this->nodes($xpath, '//c:section[c:title="Results"]/c:entry'));
    }

    /**
     * @return array{low: array{0: string, 1: ?string, 2: ?string}|null, high: array{0: string, 1: ?string, 2: ?string}|null}
     */
    private function bounds(DOMXPath $xpath, DOMElement $value): array
    {
        $bound = function (string $name) use ($xpath, $value): ?array {
            $element = $this->elements($xpath, 'c:'.$name, $value)[0] ?? null;
            if ($element === null) {
                return null;
            }

            return [
                $element->getAttribute('value'),
                $element->hasAttribute('unit') ? $element->getAttribute('unit') : null,
                $element->hasAttribute('inclusive') ? $element->getAttribute('inclusive') : null,
            ];
        };

        return ['low' => $bound('low'), 'high' => $bound('high')];
    }

    /**
     * @return array{text: ?string, bounds: array<string, ?array<int, ?string>>}
     */
    private function range(DOMXPath $xpath, string $analyte): array
    {
        $range = $this->el($xpath, '//c:observation[c:code/c:originalText="'.$analyte.'"]/c:referenceRange/c:observationRange');
        $text = $this->elements($xpath, 'c:text', $range)[0] ?? null;
        $value = $this->el($xpath, 'c:value', $range);
        $this->assertSame('IVL_PQ', $value->getAttribute('xsi:type'));
        // The range describes normal results.
        $this->assertSame('N', $this->one($xpath, 'c:interpretationCode/@code', $range)->nodeValue);

        return ['text' => $text?->textContent, 'bounds' => $this->bounds($xpath, $value)];
    }

    /**
     * @return list<string>
     */
    private function observationIds(DOMXPath $xpath): array
    {
        return array_map(static fn ($node): string => (string) $node->nodeValue, $this->nodes($xpath, '//c:observation/c:id/@root'));
    }

    /**
     * @param  list<PhrLabResult>  $labs
     * @return array{0: DOMXPath}
     */
    private function export(array $labs): array
    {
        $patient = new PhrPatient(['display_name' => 'Synthetic Patient']);
        $patient->id = 42;
        $data = ['patient' => $patient, 'lab_results' => collect($labs)];
        foreach (['vitals', 'conditions', 'medications', 'procedures', 'immunizations', 'allergies', 'office_visits', 'portal_messages', 'negative_assertions', 'dicom_studies', 'documents'] as $key) {
            $data[$key] = collect();
        }

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML((new PhrCcdaExporter)->documentXml($data)));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', 'urn:hl7-org:v3');
        $xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        return [$xpath];
    }

    /**
     * @return list<DOMNode>
     */
    private function nodes(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $result = $xpath->query($query, $context);
        $this->assertNotFalse($result, $query);

        return iterator_to_array($result, false);
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        return array_values(array_filter($this->nodes($xpath, $query, $context), static fn ($node): bool => $node instanceof DOMElement));
    }

    private function el(DOMXPath $xpath, string $query, ?DOMNode $context = null): DOMElement
    {
        $node = $this->one($xpath, $query, $context);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private function one(DOMXPath $xpath, string $query, ?DOMNode $context = null): DOMNode
    {
        $nodes = $this->nodes($xpath, $query, $context);
        $this->assertCount(1, $nodes, $query);
        $node = $nodes[0];
        $this->assertInstanceOf(DOMNode::class, $node);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $attributes
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
}
