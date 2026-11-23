<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Export\PhrPdfSummaryRenderer;
use App\Services\PHR\Fills\MedicationFillMedicationLinker;
use App\Services\PHR\Fills\MedicationFillWriter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Smalot\PdfParser\Parser;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\ImportsCcdaFixtures;
use Tests\TestCase;

/**
 * A medication's setting (#413): the home list, a hospital stay's orders and the discharge
 * list, with as-needed reasons and an infusion rate, read from C-CDA (home and discharge
 * lists) and FHIR (all three: only a source that states it gives a stay's orders), kept apart
 * on every list and export, and never a fill's medication unless it is a home one. Every
 * value is the synthetic fixture of #405; no real data.
 */
final class PhrMedicationSettingTest extends TestCase
{
    use ConfiguresPassportKeys;
    use ImportsCcdaFixtures;
    use RefreshDatabase;

    private const array HOME = ['lisinopril 10 mg oral tablet', 'metformin 500 mg oral tablet', 'acetaminophen 500 mg oral tablet'];

    /** The bundle's: a hospital stay's orders come only from a source that states them (a FHIR category). */
    private const array FHIR_HOME = ['lisinopril', 'metformin', 'acetaminophen 500 mg'];

    private const array FHIR_ORDERS = ['insulin lispro', 'ceftriaxone 1 g', 'potassium chloride 20 mEq', 'acetaminophen 650 mg', 'sodium chloride 0.9%'];

    private const array FHIR_DISCHARGE = ['lisinopril', 'metformin', 'cefpodoxime 200 mg'];

    private const array DISCHARGE = ['lisinopril 10 mg oral tablet', 'metformin 500 mg oral tablet', 'cefpodoxime 200 mg oral tablet'];

    private const string RXNORM = 'http://www.nlm.nih.gov/research/umls/rxnorm';

    private const string REQUEST_CATEGORY = 'http://terminology.hl7.org/CodeSystem/medicationrequest-category';

    private const string STATEMENT_CATEGORY = 'http://terminology.hl7.org/CodeSystem/medication-statement-category';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_the_ccda_home_and_discharge_lists_import_with_their_settings_and_the_admission_list_is_skipped(): void
    {
        $patient = $this->patient();

        $result = $this->importCcda($this->ccdaFixture('hospital-medication-lists.xml'), $patient);

        // What the patient took before admission is the home list again: skipped and counted.
        $this->assertSame([6, 3], [$result->created, $result->skipped]);
        $this->assertSame(self::HOME, $this->names($patient, 'community'));
        $this->assertSame([], $this->names($patient, 'inpatient'), 'no C-CDA section states a stay\'s orders');
        $this->assertSame(self::DISCHARGE, $this->names($patient, 'discharge'));
        $this->assertSame([true, 'pain'], $this->asNeeded($patient, 'community', 'acetaminophen 500 mg oral tablet'));
        $this->assertSame([null, null], $this->asNeeded($patient, 'community', 'lisinopril 10 mg oral tablet'), 'no precondition says nothing');
        $this->assertSame('2026-02-17', $this->medication($patient, 'discharge', 'cefpodoxime 200 mg oral tablet')->ended_on?->toDateString());
        // The discharge list's lisinopril is the home list's activity: two rows, never one moved between lists.
        $home = 'ccda-med-'.sha1('id|2.25.4131|home-lisinopril');
        $this->assertSame('community', PhrMedication::query()->where('patient_id', $patient->id)->where('external_id', $home)->value('category'));
        $this->assertSame('discharge', PhrMedication::query()->where('patient_id', $patient->id)->where('external_id', 'discharge:'.$home)->value('category'));

        $again = $this->importCcda($this->ccdaFixture('hospital-medication-lists.xml'), $patient);

        $this->assertSame([0, 0, 6], [$again->created, $again->updated, $again->unchanged]);
    }

    public function test_a_ccda_activity_s_rate_criterion_and_times_are_read(): void
    {
        $patient = $this->patient();

        $this->importCcda($this->ccdaSectionDocument(<<<'XML'
            <templateId root="2.16.840.1.113883.10.20.22.2.11.1" extension="2015-08-01"/>
            <code code="10183-2" codeSystem="2.16.840.1.113883.6.1"/>
            <title>Hospital Discharge Medications</title>
            <entry>
              <act classCode="ACT" moodCode="EVN">
                <templateId root="2.16.840.1.113883.10.20.22.4.35" extension="2016-03-01"/>
                <entryRelationship typeCode="SUBJ">
                  <substanceAdministration classCode="SBADM" moodCode="INT">
                    <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                    <id root="2.25.4134" extension="discharge-infusion"/>
                    <effectiveTime xsi:type="IVL_TS"><low value="20260212093000-0500"/><high value="20260213093000-0500"/></effectiveTime>
                    <rateQuantity value="75" unit="mL/h"/>
                    <consumable><manufacturedProduct><manufacturedMaterial>
                      <code code="9994138" codeSystem="2.16.840.1.113883.6.88" displayName="sodium chloride 0.9% injection"/>
                    </manufacturedMaterial></manufacturedProduct></consumable>
                    <precondition typeCode="PRCN">
                      <templateId root="2.16.840.1.113883.10.20.22.4.25" extension="2014-06-09"/>
                      <criterion>
                        <code code="ASSERTION" codeSystem="2.16.840.1.113883.5.4"/>
                        <value xsi:type="CD" nullFlavor="OTH"><originalText>temperature &gt; 38.0 C</originalText></value>
                      </criterion>
                    </precondition>
                  </substanceAdministration>
                </entryRelationship>
              </act>
            </entry>
            XML), $patient);

        $infusion = $this->medication($patient, 'discharge', 'sodium chloride 0.9% injection');
        $this->assertSame(['75 mL/h', true, 'temperature > 38.0 C'], [$infusion->rate, $infusion->as_needed, $infusion->as_needed_reason]);
        $this->assertSame(['2026-02-12 09:30:00', '2026-02-13 09:30:00', '2026-02-12'], [$infusion->started_at?->toDateTimeString(), $infusion->ended_at?->toDateTimeString(), $infusion->started_on?->toDateString()]);
    }

    public function test_the_three_fhir_lists_import_with_their_settings_and_a_home_medication_sent_twice_is_one_row(): void
    {
        $patient = $this->patient();

        $result = app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, $patient->owner_user_id);

        $this->assertSame(11, $result->created, 'two home medications sent as both a statement and a request are one row each');
        $this->assertSame(self::FHIR_HOME, $this->names($patient, 'community'));
        $this->assertSame(self::FHIR_ORDERS, $this->names($patient, 'inpatient'));
        $this->assertSame(self::FHIR_DISCHARGE, $this->names($patient, 'discharge'));

        // The statement is basedOn the request: one row, the statement's, with what the request adds.
        $lisinopril = $this->medication($patient, 'community', 'lisinopril');
        $this->assertSame(['MedicationStatement/ms-lisinopril', '9994131', 'Synthetic prescriber'], [$lisinopril->external_id, $lisinopril->rxnorm_code, $lisinopril->prescriber_name]);
        $this->assertCount(2, json_decode((string) $lisinopril->raw_text, true), 'the row keeps both resources as sent');
        // The only statement with the request's RxNorm code is the same medication too.
        $this->assertSame('MedicationStatement/ms-metformin', $this->medication($patient, 'community', 'metformin')->external_id);
        $this->assertSame([true, 'pain'], $this->asNeeded($patient, 'community', 'acetaminophen 500 mg'));

        $acetaminophen = $this->medication($patient, 'inpatient', 'acetaminophen 650 mg');
        $this->assertSame(['inpatient:MedicationRequest/mr-acetaminophen', true, 'temperature > 38.0 C', '650', 'mg', 'every 6 h'], [$acetaminophen->external_id, $acetaminophen->as_needed, $acetaminophen->as_needed_reason, $acetaminophen->dose, $acetaminophen->dose_unit, $acetaminophen->frequency]);
        $saline = $this->medication($patient, 'inpatient', 'sodium chloride 0.9%');
        $this->assertSame(['75 mL/h', 'intravenous', '2026-02-09 14:40:00'], [$saline->rate, $saline->route, $saline->started_at?->toDateTimeString()]);
        // The order's encounter stays in the source as sent, for the visit link (#411) to read.
        $this->assertSame('Encounter/synthetic-stay', json_decode((string) $saline->raw_text, true)['encounter']['reference']);
        $cefpodoxime = $this->medication($patient, 'discharge', 'cefpodoxime 200 mg');
        $this->assertSame(['2026-02-12', '2026-02-17', '2 times every 1 d'], [$cefpodoxime->started_on?->toDateString(), $cefpodoxime->ended_on?->toDateString(), $cefpodoxime->frequency]);
        $this->assertSame(0, PhrMedication::query()->where('patient_id', $patient->id)->whereNotNull('review_flags')->count());

        $again = app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, $patient->owner_user_id);

        $this->assertSame([0, 0, 11], [$again->created, $again->updated, $again->unchanged]);
        $this->assertSame(11, PhrMedication::query()->where('patient_id', $patient->id)->count());
    }

    public function test_a_request_that_only_may_be_a_listed_statement_is_flagged_for_review_rather_than_merged(): void
    {
        $patient = $this->patient();
        // The same name, but only the request is coded: perhaps the same medication.
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            $this->entry($this->statement('ms-ibuprofen', 'ibuprofen 200 mg', null)),
            $this->entry($this->request('mr-ibuprofen', 'community', 'ibuprofen 200 mg', '9994150')),
        ]];

        $result = app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);

        $this->assertSame(2, $result->created);
        $flagged = PhrMedication::query()->where('patient_id', $patient->id)->where('external_id', 'MedicationRequest/mr-ibuprofen')->sole();
        $this->assertSame([['code' => 'possible_duplicate', 'message' => 'May be the same home medication as another imported medication (MedicationStatement/ms-ibuprofen). Keep one and reject the other.', 'external_id' => 'MedicationStatement/ms-ibuprofen']], $flagged->review_flags);
        $this->assertSame([0, 0, 2], [($again = app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id))->created, $again->updated, $again->unchanged]);

        $items = collect($this->actingAs($patient->owner)->getJson("/api/phr/patients/{$patient->id}/review")->assertOk()->json('groups'))->flatMap(fn (array $group): array => $group['items'])->keyBy('id');
        $this->assertSame('possible_duplicate', $items[$flagged->id]['flags'][0]['code']);
        $this->assertSame([], $items[PhrMedication::query()->where('external_id', 'MedicationStatement/ms-ibuprofen')->value('id')]['flags']);

        // A request arriving in a later bundle for a statement already listed is flagged too.
        $later = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            $this->entry($this->request('mr-ibuprofen-refill', 'community', 'Ibuprofen 200 mg', null)),
        ]];
        app(PhrFhirImporter::class)->importBundle($later, $patient, $patient->owner_user_id);
        $this->assertSame('MedicationStatement/ms-ibuprofen', PhrMedication::query()->where('external_id', 'MedicationRequest/mr-ibuprofen-refill')->sole()->review_flags[0]['external_id']);
    }

    public function test_fhir_export_sends_orders_as_requests_and_home_medications_as_statements_and_reads_back_the_same(): void
    {
        $patient = $this->patient();
        app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, $patient->owner_user_id);
        PhrMedication::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $this->medication($patient, 'inpatient', 'ceftriaxone 1 g')->forceFill(['status' => 'on_hold'])->save();
        $this->medication($patient, 'inpatient', 'potassium chloride 20 mEq')->forceFill(['status' => 'discontinued'])->save();

        $bundle = $this->fhirExport($patient);

        $byName = [];
        foreach ($bundle['entry'] as $entry) {
            $resource = $entry['resource'];
            if (in_array($resource['resourceType'], ['MedicationStatement', 'MedicationRequest'], true)) {
                $byName[$resource['resourceType'].' '.$resource['medicationCodeableConcept']['text']] = $resource;
            }
        }
        $this->assertCount(11, $byName);
        $statement = $byName['MedicationStatement lisinopril'];
        $this->assertSame([['coding' => [['system' => self::STATEMENT_CATEGORY, 'code' => 'community', 'display' => 'community']]]], $statement['category']);
        $saline = $byName['MedicationRequest sodium chloride 0.9%'];
        $this->assertSame(['order', 'active', 'inpatient', self::REQUEST_CATEGORY], [$saline['intent'], $saline['status'], $saline['category'][0]['coding'][0]['code'], $saline['category'][0]['coding'][0]['system']]);
        $this->assertSame(['value' => 75, 'unit' => 'mL/h', 'system' => 'http://unitsofmeasure.org', 'code' => 'mL/h'], $saline['dosageInstruction'][0]['doseAndRate'][0]['rateQuantity']);
        $this->assertSame('on-hold', $byName['MedicationRequest ceftriaxone 1 g']['status']);
        $this->assertSame('stopped', $byName['MedicationRequest potassium chloride 20 mEq']['status']);
        $this->assertSame('discharge', $byName['MedicationRequest cefpodoxime 200 mg']['category'][0]['coding'][0]['code']);
        $this->assertSame(['text' => 'temperature > 38.0 C'], $byName['MedicationRequest acetaminophen 650 mg']['dosageInstruction'][0]['asNeededCodeableConcept']);
        $this->assertSame(['text' => 'pain'], $byName['MedicationStatement acetaminophen 500 mg']['dosage'][0]['asNeededCodeableConcept']);

        $copy = $this->patient('Pat Example Copy');
        $imported = app(PhrFhirImporter::class)->importBundle($bundle, $copy, $copy->owner_user_id);

        $this->assertSame(11, $imported->created);
        $this->assertEqualsCanonicalizing(['lisinopril', 'metformin', 'acetaminophen 500 mg'], $this->names($copy, 'community'));
        $this->assertCount(5, $this->names($copy, 'inpatient'));
        $this->assertCount(3, $this->names($copy, 'discharge'));
        $this->assertSame('75 mL/h', $this->medication($copy, 'inpatient', 'sodium chloride 0.9%')->rate);
        $this->assertSame('on_hold', $this->medication($copy, 'inpatient', 'ceftriaxone 1 g')->status);
    }

    public function test_the_ccd_lists_only_home_medications_in_its_medications_section_and_the_discharge_list_in_its_own(): void
    {
        $patient = $this->patient();
        $this->importCcda($this->ccdaFixture('hospital-medication-lists.xml'), $patient);
        // The stay's orders come from FHIR.
        app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, $patient->owner_user_id);
        PhrMedication::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);

        $xpath = $this->xpath(app(PhrCcdaExporter::class)->documentXml(app(PhrExportDataService::class)->load($patient)));

        // Rows follow the export's order, by name.
        $this->assertEqualsCanonicalizing([...self::HOME, ...self::FHIR_HOME], $this->sectionNames($xpath, '10160-0'));
        $this->assertEqualsCanonicalizing([...self::DISCHARGE, ...self::FHIR_DISCHARGE], $this->sectionNames($xpath, '10183-2'));
        $this->assertSame('75311-1', $xpath->evaluate('string(//h:section[h:code/@code="10183-2"]/h:code/h:translation/@code)'));
        $this->assertStringContainsString('every 6 h, as needed for pain', $xpath->evaluate('string(//h:section[h:code/@code="10160-0"]/h:text)'));
        foreach (self::FHIR_ORDERS as $order) {
            $this->assertSame(0, (int) $xpath->evaluate('count(//h:td[.="'.$order.'"])'), "{$order} is a hospital order");
        }

        // The PDF summary leaves the stay's orders out too, and says which list a discharge medication is on.
        $text = (new Parser)->parseContent(app(PhrPdfSummaryRenderer::class)->render(app(PhrExportDataService::class)->load($patient)))->getText();
        $this->assertStringNotContainsString('sodium chloride', $text);
        $this->assertStringContainsString('(Discharge)', $text);
        $this->assertStringContainsString('as needed for pain', $text);
    }

    public function test_the_agent_list_and_search_filter_by_setting(): void
    {
        $patient = $this->patient();
        app(PhrFhirImporter::class)->importBundle($this->bundle(), $patient, $patient->owner_user_id);
        $unknown = PhrMedication::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'import_source' => 'synthetic-agent', 'external_id' => 'unknown-1', 'name' => 'Synthetic unlisted medicine', 'status' => 'active']);
        Passport::actingAs($patient->owner, [AgentApiScopes::CLINICAL_READ]);
        $names = fn (string $query): array => collect($this->getJson("/api/v1/patients/{$patient->id}/medications?limit=100&{$query}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(self::FHIR_HOME, $names('category[]=community'));
        $this->assertSame(self::FHIR_ORDERS, $names('category[]=inpatient'));
        $this->assertSame([...self::FHIR_HOME, $unknown->name], $names('category[]=community&category[]=unknown'));
        $this->assertCount(12, $names('limit=100'), 'without a filter every setting is listed');
        $this->getJson("/api/v1/patients/{$patient->id}/lab-results?category[]=community")->assertUnprocessable();
        $this->getJson("/api/v1/patients/{$patient->id}/medications?category[]=home")->assertUnprocessable();

        $search = $this->getJson("/api/v1/patients/{$patient->id}/records/search?resource_type[]=medications&medication_category[]=inpatient&limit=100")->assertOk()->json('data');
        $this->assertCount(5, $search);
        $this->assertContains('sodium chloride 0.9% (Hospital order)', array_column($search, 'summary'));
        $this->assertCount(12, $this->getJson("/api/v1/patients/{$patient->id}/timeline?resource_type[]=medications&limit=100")->assertOk()->json('data'));
    }

    public function test_a_fill_links_only_to_a_home_medication(): void
    {
        $patient = $this->patient();
        $order = PhrMedication::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'name' => 'ceftriaxone', 'rxnorm_code' => '9994135', 'category' => 'inpatient', 'status' => 'active', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $discharge = PhrMedication::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'name' => 'cefpodoxime', 'rxnorm_code' => '9994140', 'category' => 'discharge', 'status' => 'active', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $unknown = PhrMedication::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'name' => 'lisinopril', 'rxnorm_code' => '9994131', 'status' => 'active', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $fill = fn (string $rxcui): PhrMedicationFill => PhrMedicationFill::factory()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'rxcui' => $rxcui, 'brand_name' => 'Synthetic Brand', 'medication_id' => null]);
        $linker = app(MedicationFillMedicationLinker::class);

        $ordered = $linker->attach($patient, $fill('9994135'));
        $home = $linker->attach($patient, $fill('9994131'));

        $this->assertTrue($ordered['created'], 'a hospital order with the same code is not the fill\'s medication');
        $this->assertNotSame($order->id, $ordered['medication']->id);
        $this->assertSame(['community', 'Synthetic Brand'], [$ordered['medication']->category, $ordered['medication']->brand_name]);
        $this->assertSame($unknown->id, $home['medication']->id, 'a medication of unknown setting is on the home list');
        try {
            app(MedicationFillWriter::class)->prepare($patient, ['medication_id' => $discharge->id, 'drug_as_printed' => 'cefpodoxime 200 mg'], null, false);
            $this->fail('A fill was linked to a discharge medication.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('medication_id', $exception->errors(), json_encode($exception->errors()));
        }
    }

    /** @return array<string, mixed> */
    private function bundle(): array
    {
        $timing = static fn (int $frequency, int $period, string $unit): array => ['repeat' => ['frequency' => $frequency, 'period' => $period, 'periodUnit' => $unit]];

        return ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            $this->entry([...$this->statement('ms-lisinopril', 'lisinopril', '9994131'), 'basedOn' => [['reference' => 'MedicationRequest/mr-home-lisinopril']]]),
            $this->entry($this->statement('ms-metformin', 'metformin', '9994132')),
            $this->entry($this->statement('ms-acetaminophen', 'acetaminophen 500 mg', '9994133', ['text' => '500 mg every 6 hours as needed for pain', 'asNeededCodeableConcept' => ['text' => 'pain']])),
            $this->entry([...$this->request('mr-home-lisinopril', 'community', 'lisinopril', '9994131'), 'requester' => ['display' => 'Synthetic prescriber']]),
            $this->entry($this->request('mr-home-metformin', 'community', 'metformin', '9994132')),
            $this->entry($this->request('mr-insulin', 'inpatient', 'insulin lispro', '9994134', ['text' => 'Sliding scale with meals', 'route' => ['text' => 'subcutaneous']])),
            $this->entry($this->request('mr-ceftriaxone', 'inpatient', 'ceftriaxone 1 g', '9994135', ['timing' => $timing(1, 24, 'h'), 'route' => ['text' => 'intravenous'], 'doseAndRate' => [['doseQuantity' => ['value' => 1, 'unit' => 'g']]]])),
            $this->entry($this->request('mr-potassium', 'inpatient', 'potassium chloride 20 mEq', '9994136', ['text' => 'once', 'doseAndRate' => [['doseQuantity' => ['value' => 20, 'unit' => 'meq']]]])),
            $this->entry($this->request('mr-acetaminophen', 'inpatient', 'acetaminophen 650 mg', '9994137', ['timing' => $timing(1, 6, 'h'), 'asNeededCodeableConcept' => ['text' => 'temperature > 38.0 C'], 'doseAndRate' => [['doseQuantity' => ['value' => 650, 'unit' => 'mg']]]])),
            $this->entry($this->request('mr-saline', 'inpatient', 'sodium chloride 0.9%', '9994138', [
                'route' => ['text' => 'intravenous'],
                'timing' => ['repeat' => ['boundsPeriod' => ['start' => '2026-02-09T14:40:00-05:00']]],
                'doseAndRate' => [['rateQuantity' => ['value' => 75, 'unit' => 'mL/h', 'system' => 'http://unitsofmeasure.org', 'code' => 'mL/h']]],
            ])),
            $this->entry($this->request('mr-discharge-lisinopril', 'discharge', 'lisinopril', '9994131')),
            $this->entry($this->request('mr-discharge-metformin', 'discharge', 'metformin', '9994132')),
            $this->entry($this->request('mr-cefpodoxime', 'discharge', 'cefpodoxime 200 mg', '9994140', [
                'timing' => ['repeat' => ['boundsDuration' => ['value' => 5, 'unit' => 'days', 'code' => 'd'], 'frequency' => 2, 'period' => 1, 'periodUnit' => 'd']],
                'doseAndRate' => [['doseQuantity' => ['value' => 200, 'unit' => 'mg']]],
            ], '2026-02-12')),
        ]];
    }

    /**
     * @param  array<string, mixed>|null  $dosage
     * @return array<string, mixed>
     */
    private function statement(string $id, string $name, ?string $rxnorm, ?array $dosage = null): array
    {
        return array_filter([
            'resourceType' => 'MedicationStatement',
            'id' => $id,
            'status' => 'active',
            'category' => [['coding' => [['system' => self::STATEMENT_CATEGORY, 'code' => 'community']]]],
            'medicationCodeableConcept' => $this->concept($name, $rxnorm),
            'subject' => ['reference' => 'Patient/synthetic'],
            'effectivePeriod' => ['start' => '2025-01-01'],
            'dosage' => $dosage === null ? null : [$dosage],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $dosage
     * @return array<string, mixed>
     */
    private function request(string $id, string $category, string $name, ?string $rxnorm, array $dosage = [], ?string $authoredOn = null): array
    {
        return array_filter([
            'resourceType' => 'MedicationRequest',
            'id' => $id,
            'status' => 'active',
            'intent' => 'order',
            'category' => [['coding' => [['system' => self::REQUEST_CATEGORY, 'code' => $category]]]],
            'medicationCodeableConcept' => $this->concept($name, $rxnorm),
            'subject' => ['reference' => 'Patient/synthetic'],
            'encounter' => $category === 'inpatient' ? ['reference' => 'Encounter/synthetic-stay'] : null,
            'authoredOn' => $authoredOn,
            'dosageInstruction' => $dosage === [] ? null : [$dosage],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function concept(string $name, ?string $rxnorm): array
    {
        return ['text' => $name, ...($rxnorm === null ? [] : ['coding' => [['system' => self::RXNORM, 'code' => $rxnorm, 'display' => $name]]])];
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array{fullUrl: string, resource: array<string, mixed>}
     */
    private function entry(array $resource): array
    {
        return ['fullUrl' => 'urn:uuid:'.$resource['id'], 'resource' => $resource];
    }

    /** @return list<string> */
    private function names(PhrPatient $patient, string $category): array
    {
        return PhrMedication::query()->where('patient_id', $patient->id)->where('category', $category)->whereNull('retracted_at')->orderBy('id')->pluck('name')->all();
    }

    private function medication(PhrPatient $patient, string $category, string $name): PhrMedication
    {
        return PhrMedication::query()->where('patient_id', $patient->id)->where('category', $category)->where('name', $name)->sole();
    }

    /** @return array{0: bool|null, 1: string|null} */
    private function asNeeded(PhrPatient $patient, string $category, string $name): array
    {
        $medication = $this->medication($patient, $category, $name);

        return [$medication->as_needed, $medication->as_needed_reason];
    }

    /** @return array<string, mixed> */
    private function fhirExport(PhrPatient $patient): array
    {
        return json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('h', 'urn:hl7-org:v3');

        return $xpath;
    }

    /** @return list<string> the first cell of each row of the section with this LOINC code */
    private function sectionNames(DOMXPath $xpath, string $code): array
    {
        $names = [];
        foreach ($xpath->query('//h:section[h:code/@code="'.$code.'"]/h:text/h:table/h:tbody/h:tr/h:td[1]') ?: [] as $cell) {
            $names[] = trim((string) $cell->textContent);
        }

        return $names;
    }

    private function patient(string $name = 'Pat Example'): PhrPatient
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $name,
            'relationship' => 'self',
            'birth_date' => '1950-06-15',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);
        Carbon::setTestNow('2026-02-13 12:00:00');

        return $patient;
    }
}
