<?php

namespace Tests\Unit\Services\PHR;

use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Services\PHR\Export\PhrCcdaExporter;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Tests\TestCase;

/**
 * The Encounters section's Encounter Activity entries beside the narrative table,
 * including the value-set assertions the validation gate's engine cannot resolve
 * (scripts/validate-ccda.mjs). Synthetic data only.
 */
class PhrCcdaEncounterEntriesTest extends TestCase
{
    private const string ACTIVITY = '2.16.840.1.113883.10.20.22.4.49';

    private const string DIAGNOSIS = '2.16.840.1.113883.10.20.22.4.80';

    private const string PROBLEM = '2.16.840.1.113883.10.20.22.4.4';

    /** ActStatus codes. */
    private const array ACT_STATUS = ['normal', 'aborted', 'active', 'cancelled', 'completed', 'held', 'new', 'suspended', 'nullified', 'obsolete'];

    /** The SNOMED CT concepts of the C-CDA Problem Type value set. */
    private const array PROBLEM_TYPE = ['55607006', '404684003', '409586006', '282291009', '64572001', '248536006', '418799008'];

    public function test_each_visit_is_an_encounter_activity_that_references_its_narrative_row(): void
    {
        $xpath = $this->export([
            $this->visit(1, ['visit_type' => 'Synthetic office visit', 'visit_date' => '2026-01-02', 'provider_name' => 'Synthetic Clinician']),
            $this->visit(2, [
                'visit_type' => 'Synthetic hospital admission', 'visit_started_at' => '2026-02-09 14:20:00', 'visit_ended_at' => '2026-02-14 11:00:00',
                'facility_name' => 'Synthetic hospital', 'location' => 'Synthetic unit', 'encounter_class' => 'IMP', 'encounter_state' => 'in-progress',
                'discharge_disposition' => 'other-hcf', 'discharge_disposition_text' => 'Synthetic transfer',
            ]),
            $this->visit(3, ['encounter_class' => 'EMER']),
        ]);

        $activities = $this->elements($xpath, '//c:section[c:title="Encounters"]/c:entry/c:encounter');
        $this->assertCount(3, $activities);
        $ids = [];
        foreach ($activities as $i => $activity) {
            $this->assertSame(['ENC', 'EVN'], [$activity->getAttribute('classCode'), $activity->getAttribute('moodCode')]);
            $this->assertSame('2015-08-01', $this->one($xpath, 'c:templateId[@root="'.self::ACTIVITY.'"]/@extension', $activity)->nodeValue);
            $ids[] = $this->one($xpath, 'c:id/@root', $activity)->nodeValue;
            $reference = ltrim((string) $this->one($xpath, 'c:text/c:reference/@value', $activity)->nodeValue, '#');
            $this->assertSame('encounter-'.($i + 1), $reference);
            $this->el($xpath, '//c:tr[@ID="'.$reference.'"]');
        }
        $this->assertCount(3, array_unique($ids));

        [$office, $stay, $emergency] = $activities;
        $this->assertSame('Synthetic office visit', $this->one($xpath, 'c:code/c:originalText', $office)->textContent);
        $this->assertSame('20260102', $this->one($xpath, 'c:effectiveTime/@value', $office)->nodeValue);
        $this->assertCount(0, $this->nodes($xpath, 'c:statusCode', $office), 'no state is stated when none is stored');
        $this->assertSame('Synthetic Clinician', $this->one($xpath, 'c:performer/c:assignedEntity/c:assignedPerson/c:name', $office)->textContent);

        // The class is a translation with no words of its own; the type is the original text.
        $translation = $this->el($xpath, 'c:code/c:translation', $stay);
        $this->assertSame(['IMP', '2.16.840.1.113883.5.4', false], [$translation->getAttribute('code'), $translation->getAttribute('codeSystem'), $translation->hasAttribute('displayName')]);
        $this->assertSame('active', $this->one($xpath, 'c:statusCode/@code', $stay)->nodeValue);
        $this->assertSame(['20260209142000+0000', '20260214110000+0000'], [$this->one($xpath, 'c:effectiveTime/c:low/@value', $stay)->nodeValue, $this->one($xpath, 'c:effectiveTime/c:high/@value', $stay)->nodeValue]);
        $this->assertSame('Synthetic unit', $this->one($xpath, 'c:participant[@typeCode="LOC"]/c:participantRole[@classCode="SDLOC"]/c:playingEntity/c:name', $stay)->textContent);
        $this->assertSame('Synthetic hospital', $this->one($xpath, 'c:performer/c:assignedEntity/c:representedOrganization/c:name', $stay)->textContent);
        // other-hcf has no NUBC status of the same meaning: the words, null-flavored.
        $disposition = $this->el($xpath, 'sdtc:dischargeDispositionCode', $stay);
        $this->assertSame(['OTH', false, 'Synthetic transfer'], [$disposition->getAttribute('nullFlavor'), $disposition->hasAttribute('code'), $this->one($xpath, 'c:originalText', $disposition)->textContent]);

        $this->assertSame('UNK', $this->one($xpath, 'c:code/@nullFlavor', $emergency)->nodeValue);
        $this->assertCount(0, $this->nodes($xpath, 'c:code/c:originalText', $emergency));
        $this->assertSame('UNK', $this->one($xpath, 'c:effectiveTime/@nullFlavor', $emergency)->nodeValue);
    }

    public function test_diagnoses_are_encounter_diagnoses_holding_problem_observations_from_their_value_sets(): void
    {
        $xpath = $this->export([$this->visit(1, [
            'visit_date' => '2026-02-09',
            'encounter_diagnoses' => [
                ['name' => 'Synthetic pneumonia', 'code' => 'J18.9', 'code_system' => 'http://hl7.org/fhir/sid/icd-10-cm'],
                ['name' => 'Synthetic named only'],
                ['name' => 'Synthetic local code', 'code' => 'X1', 'code_system' => 'urn:synthetic'],
            ],
            // A code the diagnoses already name is not repeated; another one is added.
            'icd10_codes' => [['code' => 'J18.9', 'description' => 'Synthetic pneumonia'], ['code' => 'E87.6', 'description' => 'Synthetic hypokalemia']],
        ])]);

        $acts = $this->elements($xpath, '//c:encounter/c:entryRelationship[@typeCode="COMP"]/c:act');
        $this->assertCount(4, $acts);
        foreach ($acts as $act) {
            $this->assertSame('2024-05-01', $this->one($xpath, 'c:templateId[@root="'.self::DIAGNOSIS.'"]/@extension', $act)->nodeValue);
            $this->assertSame(['29308-4', '2.16.840.1.113883.6.1'], [$this->one($xpath, 'c:code/@code', $act)->nodeValue, $this->one($xpath, 'c:code/@codeSystem', $act)->nodeValue]);
            $this->assertContains($this->one($xpath, 'c:statusCode/@code', $act)->nodeValue, self::ACT_STATUS);
            $this->assertSame('completed', $this->one($xpath, 'c:statusCode/@code', $act)->nodeValue);
            $problem = $this->el($xpath, 'c:entryRelationship[@typeCode="SUBJ"]/c:observation', $act);
            $this->assertSame('2024-05-01', $this->one($xpath, 'c:templateId[@root="'.self::PROBLEM.'"]/@extension', $problem)->nodeValue);
            $this->assertContains($this->one($xpath, 'c:statusCode/@code', $problem)->nodeValue, self::ACT_STATUS);
            $this->assertSame('2.16.840.1.113883.6.96', $this->one($xpath, 'c:code/@codeSystem', $problem)->nodeValue);
            $this->assertContains($this->one($xpath, 'c:code/@code', $problem)->nodeValue, self::PROBLEM_TYPE);
            $this->assertSame('20260209', $this->one($xpath, 'c:effectiveTime/c:low/@value', $problem)->nodeValue);
        }

        $value = fn (int $i): DOMElement => $this->el($xpath, 'c:entryRelationship/c:observation/c:value', $acts[$i]);
        $this->assertSame(['J18.9', '2.16.840.1.113883.6.90', 'Synthetic pneumonia'], [$value(0)->getAttribute('code'), $value(0)->getAttribute('codeSystem'), $value(0)->getAttribute('displayName')]);
        foreach ([1 => 'Synthetic named only', 2 => 'Synthetic local code'] as $i => $name) {
            // A name with no code from a system C-CDA names is never given a guessed one.
            $this->assertSame(['OTH', false, $name], [$value($i)->getAttribute('nullFlavor'), $value($i)->hasAttribute('code'), $this->one($xpath, 'c:originalText', $value($i))->textContent]);
        }
        $this->assertSame('E87.6', $value(3)->getAttribute('code'));
        // Every diagnosis references the visit's item in the list after the table, which names them.
        foreach ($acts as $act) {
            $this->assertSame('#encounter-1-detail', $this->one($xpath, 'c:text/c:reference/@value', $act)->nodeValue);
            $this->assertSame('#encounter-1-detail', $this->one($xpath, 'c:entryRelationship/c:observation/c:text/c:reference/@value', $act)->nodeValue);
        }
        $this->assertSame('Visit 1: diagnoses Synthetic pneumonia, Synthetic named only, Synthetic local code, Synthetic hypokalemia', $this->one($xpath, '//c:section[c:title="Encounters"]/c:text/c:list/c:item[@ID="encounter-1-detail"]')->textContent);
    }

    public function test_ids_are_stable_across_exports_and_an_export_without_visits_has_no_entries(): void
    {
        $first = $this->export([$this->visit(7, ['visit_type' => 'Synthetic visit'])]);
        $second = $this->export([$this->visit(7, ['visit_type' => 'Synthetic visit, edited'])]);
        $this->assertSame($this->one($first, '//c:encounter/c:id/@root')->nodeValue, $this->one($second, '//c:encounter/c:id/@root')->nodeValue);

        $none = $this->export([]);
        $this->assertSame('NI', $this->one($none, '//c:section[c:title="Encounters"]/@nullFlavor')->nodeValue);
        $this->assertCount(0, $this->nodes($none, '//c:section[c:title="Encounters"]/c:entry'));
    }

    public function test_the_table_keeps_its_five_columns_and_coded_facts_follow_it_in_a_list(): void
    {
        $xpath = $this->export([
            $this->visit(1, ['visit_type' => 'Synthetic visit', 'facility_name' => 'Synthetic clinic']),
            $this->visit(2, [
                'visit_type' => 'Synthetic stay', 'facility_name' => 'Synthetic hospital', 'location' => 'Synthetic unit',
                'encounter_class' => 'IMP', 'encounter_state' => 'finished', 'discharge_disposition' => 'snf',
            ]),
        ]);

        $headers = array_map(static fn (DOMNode $node): string => $node->textContent, $this->nodes($xpath, '//c:section[c:title="Encounters"]/c:text/c:table/c:thead/c:tr/c:th'));
        $this->assertSame(['Date', 'Type', 'Provider', 'Reason', 'Assessment'], $headers, 'a document first read from this table is still adopted by its rows');
        $items = $this->elements($xpath, '//c:section[c:title="Encounters"]/c:text/c:list/c:item');
        $this->assertCount(1, $items, 'a visit stating nothing the table lacks has no item');
        $this->assertSame('encounter-2-detail', $items[0]->getAttribute('ID'));
        $this->assertSame('Synthetic stay: class inpatient encounter; status finished; location Synthetic unit; facility Synthetic hospital; discharged to Skilled nursing facility', $items[0]->textContent);

        $plain = $this->export([$this->visit(1, ['visit_type' => 'Synthetic visit'])]);
        $this->assertCount(0, $this->nodes($plain, '//c:list'));
    }

    /**
     * @param  list<PhrOfficeVisit>  $visits
     */
    private function export(array $visits): DOMXPath
    {
        $patient = new PhrPatient(['display_name' => 'Synthetic Patient']);
        $patient->id = 42;
        $data = ['patient' => $patient, 'office_visits' => collect($visits)];
        foreach (['lab_results', 'vitals', 'conditions', 'medications', 'procedures', 'immunizations', 'allergies', 'portal_messages', 'negative_assertions', 'dicom_studies', 'documents'] as $key) {
            $data[$key] = collect();
        }

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML((new PhrCcdaExporter)->documentXml($data)));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', 'urn:hl7-org:v3');
        $xpath->registerNamespace('sdtc', 'urn:hl7-org:sdtc');

        return $xpath;
    }

    /** @param  array<string, mixed>  $attributes */
    private function visit(int $id, array $attributes): PhrOfficeVisit
    {
        $visit = new PhrOfficeVisit(['patient_id' => 42, ...$attributes]);
        $visit->id = $id;

        return $visit;
    }

    /** @return list<DOMNode> */
    private function nodes(DOMXPath $xpath, string $query, ?DOMNode $context = null): array
    {
        $result = $xpath->query($query, $context);
        $this->assertNotFalse($result, $query);

        return iterator_to_array($result, false);
    }

    /** @return list<DOMElement> */
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

        return $nodes[0];
    }
}
