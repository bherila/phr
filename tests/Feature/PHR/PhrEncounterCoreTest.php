<?php

namespace Tests\Feature\PHR;

use App\Models\PhrCondition;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Import\Ehi\Mapping\EhiTargetDigest;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * A visit's coded encounter facts (#410): class, state, unit, admit source, discharge
 * disposition and diagnoses, written through MCP and carried through FHIR and C-CDA.
 * Every value is the synthetic inpatient stay of #405; no real data.
 */
final class PhrEncounterCoreTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const string ICD10 = 'http://hl7.org/fhir/sid/icd-10-cm';

    private const array DIAGNOSES = ['Community-acquired pneumonia', 'Acute kidney injury', 'Hypokalemia'];

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_the_synthetic_inpatient_stay_round_trips_fhir_to_phr_to_fhir_without_a_second_visit(): void
    {
        $patient = $this->patient();

        $result = app(PhrFhirImporter::class)->importBundle($this->inpatientBundle(), $patient, $patient->owner_user_id);

        // The ClinicalImpression assessing the stay is part of it, not a visit of its own.
        $this->assertSame(1, $result->created);
        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame('Encounter/synthetic-stay', $visit->external_id);
        $this->assertStayFields($visit);
        $this->assertSame('Synthetic chief complaint', $visit->chief_complaint);
        $this->assertSame('Synthetic assessment from the impression', $visit->assessment, 'a summary written as an object is read too');

        $visit->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $exported = $this->fhirExport($patient);
        $encounters = array_values(array_filter($exported['entry'], static fn (array $entry): bool => $entry['resource']['resourceType'] === 'Encounter'));
        $this->assertCount(1, $encounters);
        $encounter = $encounters[0]['resource'];
        $this->assertSame(['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP', 'display' => 'inpatient encounter'], $encounter['class']);
        $this->assertSame('in-progress', $encounter['status']);
        $this->assertSame([['location' => ['display' => '6 North Telemetry']]], $encounter['location']);
        $this->assertSame('Example General Hospital', $encounter['serviceProvider']['display']);
        $this->assertSame('emd', $encounter['hospitalization']['admitSource']['coding'][0]['code']);
        $this->assertSame('Emergency department', $encounter['hospitalization']['admitSource']['text']);
        $this->assertSame([1, 2, 3], array_column($encounter['diagnosis'], 'rank'));
        $this->assertSame(self::DIAGNOSES, array_map(static fn (array $condition): string => $condition['code']['text'], $encounter['contained']));
        $this->assertSame('J18.9', $encounter['contained'][0]['code']['coding'][0]['code']);

        // PHR's own export reads back as the same single visit, and again changes nothing.
        $other = $this->patient('Pat Example Copy');
        $again = app(PhrFhirImporter::class)->importBundle($exported, $other, $other->owner_user_id);
        $copy = PhrOfficeVisit::query()->where('patient_id', $other->id)->sole();
        $this->assertSame(1, $again->created);
        $this->assertStayFields($copy);
        $this->assertSame('Synthetic chief complaint', $copy->chief_complaint);
        $this->assertSame('Synthetic assessment from the impression', $copy->assessment, 'the exported summary string is read');

        $third = app(PhrFhirImporter::class)->importBundle($exported, $other, $other->owner_user_id);
        $this->assertSame(0, $third->created);
        $this->assertSame(0, $third->updated);
        $this->assertSame(1, PhrOfficeVisit::query()->where('patient_id', $other->id)->count());
    }

    public function test_a_resource_without_an_id_is_named_by_its_full_url_or_its_content_and_imports_once(): void
    {
        $patient = $this->patient();
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['fullUrl' => 'urn:uuid:5b7e1f8a-0000-4000-8000-000000000410', 'resource' => [
                'resourceType' => 'Encounter', 'status' => 'finished', 'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB'],
                'period' => ['start' => '2026-01-05'], 'type' => [['text' => 'Synthetic id-less visit']],
            ]],
            ['resource' => ['resourceType' => 'Condition', 'code' => ['text' => 'Synthetic id-less condition']]],
            ['fullUrl' => 'urn:uuid:5b7e1f8a-0000-4000-8000-000000000411', 'resource' => [
                'resourceType' => 'ClinicalImpression', 'status' => 'completed',
                'encounter' => ['reference' => 'urn:uuid:5b7e1f8a-0000-4000-8000-000000000410'],
                'summary' => 'Synthetic assessment of an id-less visit',
            ]],
        ]];

        $first = app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);
        $second = app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);

        $this->assertSame(2, $first->created, 'one visit (the impression folds into it by fullUrl) and one condition');
        $this->assertSame([0, 0, 2], [$second->created, $second->updated, $second->unchanged]);
        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame('Encounter/fullUrl:urn:uuid:5b7e1f8a-0000-4000-8000-000000000410', $visit->external_id);
        $this->assertSame('AMB', $visit->encounter_class);
        $this->assertSame('finished', $visit->encounter_state);
        $this->assertSame('Synthetic assessment of an id-less visit', $visit->assessment);
        $this->assertStringStartsWith('Condition/sha256:', (string) PhrCondition::query()->where('patient_id', $patient->id)->sole()->external_id);
    }

    public function test_an_impression_whose_encounter_is_not_in_the_bundle_is_still_a_visit(): void
    {
        $patient = $this->patient();
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [['resource' => [
            'resourceType' => 'ClinicalImpression', 'id' => 'synthetic-impression', 'status' => 'completed', 'date' => '2026-01-07',
            'encounter' => ['reference' => 'Encounter/somewhere-else'], 'description' => 'Synthetic reason', 'summary' => 'Synthetic summary string',
        ]]]];

        app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);

        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame('ClinicalImpression/synthetic-impression', $visit->external_id);
        $this->assertSame('Synthetic summary string', $visit->assessment);
        $this->assertSame('Synthetic reason', $visit->chief_complaint);
    }

    public function test_an_export_states_a_visit_without_a_coded_state_from_its_dates(): void
    {
        $patient = $this->patient();
        $this->confirmedVisit($patient, ['visit_type' => 'Synthetic past office visit', 'visit_date' => '2026-01-02']);
        $this->confirmedVisit($patient, ['visit_type' => 'Synthetic open stay', 'visit_date' => '2026-01-03', 'visit_started_at' => '2026-01-03 10:00:00', 'encounter_class' => 'IMP']);
        $this->confirmedVisit($patient, ['visit_type' => 'Synthetic ended stay', 'visit_date' => '2026-01-04', 'visit_ended_at' => '2026-01-06 10:00:00', 'encounter_class' => 'IMP']);
        $this->confirmedVisit($patient, ['visit_type' => 'Synthetic undated visit']);

        $encounters = collect($this->fhirExport($patient)['entry'])->pluck('resource')->where('resourceType', 'Encounter')->keyBy(static fn (array $encounter): string => $encounter['type'][0]['text']);

        $this->assertSame('finished', $encounters['Synthetic past office visit']['status']);
        $this->assertSame('unknown', $encounters['Synthetic open stay']['status'], 'a stay with no end may still be under way');
        $this->assertSame('finished', $encounters['Synthetic ended stay']['status']);
        $this->assertSame('unknown', $encounters['Synthetic undated visit']['status']);
        $this->assertSame('UNK', $encounters['Synthetic past office visit']['class']['code'], 'R4 requires a class; none stated is a null flavor');
        $this->assertArrayNotHasKey('hospitalization', $encounters['Synthetic past office visit']);
    }

    public function test_a_visit_written_over_mcp_as_inpatient_reads_back_and_exports_with_that_class(): void
    {
        [, $patient] = $this->mcpSession();

        $rejected = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => 'office_visits.upsert', 'arguments' => $this->mcpVisit($patient, ['encounter_class' => 'INPATIENT'])]]);
        $this->assertSame('/data/encounter_class', $rejected->json('error.data.validation_errors.0.pointer'), 'only an ActCode class is accepted');
        $rest = $this->mcpVisit($patient, ['admit_source' => 'emergency department']);
        unset($rest['patient_id']);
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $rest)->assertUnprocessable()->assertJsonValidationErrors(['data.admit_source']);
        $this->assertSame(0, PhrOfficeVisit::query()->where('patient_id', $patient->id)->count());

        $created = $this->tool('office_visits.upsert', $this->mcpVisit($patient, []));
        $this->assertFalse($created['isError'] ?? false, json_encode($created));
        $this->assertSame('created', $created['structuredContent']['outcome']);
        $data = $created['structuredContent']['data'];
        $this->assertSame(['IMP', 'in-progress', '6 North Telemetry', 'emd', 'Emergency department'], [$data['encounter_class'], $data['encounter_state'], $data['location'], $data['admit_source'], $data['admit_source_text']]);
        $this->assertSame(self::DIAGNOSES, array_column($data['encounter_diagnoses'], 'name'));
        $this->assertSame('clinical', $data['encounter_category']);
        $this->assertNull($data['encounter_status'], 'the source appointment status is not written through the API');

        $updated = $this->tool('office_visits.update', [
            'patient_id' => $patient->id, 'record_id' => $data['id'], 'expected_version' => $created['structuredContent']['version'],
            'data' => ['encounter_state' => 'finished', 'visit_ended_at' => '2026-02-14T11:00:00Z', 'discharge_disposition' => 'home', 'discharge_disposition_text' => 'Home with family'],
        ]);
        $this->assertFalse($updated['isError'] ?? false, json_encode($updated));
        $read = $this->tool('clinical.get', ['resource_type' => 'office-visits', 'patient_id' => $patient->id, 'record_id' => $data['id']]);
        $this->assertSame(['finished', 'home', 'IMP'], [$read['structuredContent']['data']['encounter_state'], $read['structuredContent']['data']['discharge_disposition'], $read['structuredContent']['data']['encounter_class']]);

        $found = $this->tool('records.search', ['patient_id' => $patient->id, 'q' => 'Telemetry', 'resource_type' => ['office-visits']]);
        $this->assertSame([$data['id']], array_column($found['structuredContent']['data'], 'id'), 'the unit is searchable');

        PhrOfficeVisit::query()->whereKey($data['id'])->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $encounter = collect($this->fhirExport($patient->refresh())['entry'])->pluck('resource')->firstWhere('resourceType', 'Encounter');
        $this->assertSame('IMP', $encounter['class']['code']);
        $this->assertSame('finished', $encounter['status']);
        $this->assertSame(['system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition', 'code' => 'home', 'display' => 'Home'], $encounter['hospitalization']['dischargeDisposition']['coding'][0]);
        $this->assertSame('Home with family', $encounter['hospitalization']['dischargeDisposition']['text']);
    }

    public function test_a_documents_encompassing_encounter_is_its_visit_and_an_encounter_activity_with_its_id_is_the_same_visit(): void
    {
        $patient = $this->patient();

        $alone = app(PhrCcdaImporter::class)->importXml($this->dischargeSummary(false), $patient, $patient->owner_user_id);

        $this->assertSame(1, $alone->created);
        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame([
            'encounter_class' => 'IMP',
            'location' => '6 North Telemetry',
            'facility_name' => 'Example General Hospital',
            'provider_name' => 'Alex Attending MD',
            'discharge_disposition' => 'home',
            'discharge_disposition_text' => 'Home with family',
            'admit_source_text' => 'Emergency department',
            'visit_date' => '2026-02-09',
        ], [
            'encounter_class' => $visit->encounter_class,
            'location' => $visit->location,
            'facility_name' => $visit->facility_name,
            'provider_name' => $visit->provider_name,
            'discharge_disposition' => $visit->discharge_disposition,
            'discharge_disposition_text' => $visit->discharge_disposition_text,
            'admit_source_text' => $visit->admit_source_text,
            'visit_date' => $visit->visit_date?->toDateString(),
        ]);
        $this->assertSame(0, app(PhrCcdaImporter::class)->importXml($this->dischargeSummary(false), $patient, $patient->owner_user_id)->created);

        // The same encounter in the Encounters section is one visit: the activity's own
        // facts, with what only the header says.
        $both = $this->patient('Pat Example Both');
        app(PhrCcdaImporter::class)->importXml($this->dischargeSummary(true), $both, $both->owner_user_id);
        $merged = PhrOfficeVisit::query()->where('patient_id', $both->id)->sole();
        $this->assertSame('Synthetic hospital admission', $merged->visit_type);
        $this->assertSame('IMP', $merged->encounter_class);
        $this->assertSame('home', $merged->discharge_disposition);
        $this->assertSame('6 North Telemetry', $merged->location);
        $this->assertSame(self::DIAGNOSES, array_column($merged->encounter_diagnoses ?? [], 'name'));
    }

    public function test_a_ccda_export_carries_each_visit_as_an_encounter_activity_that_reads_back_as_the_same_visit(): void
    {
        $patient = $this->patient();
        $this->confirmedVisit($patient, [
            'visit_type' => 'Synthetic hospital admission',
            'visit_date' => '2026-02-09',
            'visit_started_at' => '2026-02-09 14:20:00',
            'visit_ended_at' => '2026-02-14 11:00:00',
            'provider_name' => 'Alex Attending, MD',
            'facility_name' => 'Example General Hospital',
            'location' => '6 North Telemetry',
            'encounter_class' => 'IMP',
            'encounter_state' => 'finished',
            'discharge_disposition' => 'home',
            'encounter_diagnoses' => [
                ['name' => 'Community-acquired pneumonia', 'code' => 'J18.9', 'code_system' => self::ICD10],
                ['name' => 'Acute kidney injury'],
                ['name' => 'Hypokalemia', 'code' => 'E87.6', 'code_system' => self::ICD10],
            ],
        ]);

        $xml = app(PhrCcdaExporter::class)->documentXml(app(PhrExportDataService::class)->load($patient));
        $this->assertStringContainsString('<sdtc:dischargeDispositionCode code="01" codeSystem="2.16.840.1.113883.6.301.5"', $xml);

        $other = $this->patient('Pat Example Copy');
        $first = app(PhrCcdaImporter::class)->importXml($xml, $other, $other->owner_user_id);
        $again = app(PhrCcdaImporter::class)->importXml($xml, $other, $other->owner_user_id);

        $copy = PhrOfficeVisit::query()->where('patient_id', $other->id)->sole();
        $this->assertSame(1, $first->created);
        $this->assertSame([0, 0], [$again->created, $again->updated]);
        $this->assertSame([
            'Synthetic hospital admission', 'Alex Attending, MD', 'Example General Hospital', '6 North Telemetry', 'IMP', 'finished', 'home', null,
        ], [
            $copy->visit_type, $copy->provider_name, $copy->facility_name, $copy->location, $copy->encounter_class, $copy->encounter_state, $copy->discharge_disposition, $copy->discharge_disposition_text,
        ]);
        $this->assertSame(self::DIAGNOSES, array_column($copy->encounter_diagnoses ?? [], 'name'));
        $this->assertSame([['code' => 'J18.9', 'description' => 'Community-acquired pneumonia'], ['code' => 'E87.6', 'description' => 'Hypokalemia']], $copy->icd10_codes);
    }

    public function test_a_pending_ehi_proposal_s_target_digest_survives_the_new_columns_until_one_is_set(): void
    {
        $patient = $this->patient();
        $visit = $this->confirmedVisit($patient, ['visit_type' => 'Synthetic visit', 'visit_date' => '2026-01-02'])->refresh();
        $before = $visit->getAttributes();
        foreach (PhrOfficeVisit::ENCOUNTER_CORE as $column) {
            unset($before[$column]);
        }
        $older = (new PhrOfficeVisit)->setRawAttributes($before, true);

        $this->assertSame(EhiTargetDigest::of($older), EhiTargetDigest::of($visit), 'the null columns a migration added are not a change');
        $visit->forceFill(['encounter_class' => 'AMB']);
        $this->assertNotSame(EhiTargetDigest::of($older), EhiTargetDigest::of($visit));
    }

    private function assertStayFields(PhrOfficeVisit $visit): void
    {
        $this->assertSame([
            'encounter_class' => 'IMP',
            'encounter_state' => 'in-progress',
            'location' => '6 North Telemetry',
            'facility_name' => 'Example General Hospital',
            'admit_source' => 'emd',
            'admit_source_text' => 'Emergency department',
            'visit_date' => '2026-02-09',
        ], [
            'encounter_class' => $visit->encounter_class,
            'encounter_state' => $visit->encounter_state,
            'location' => $visit->location,
            'facility_name' => $visit->facility_name,
            'admit_source' => $visit->admit_source,
            'admit_source_text' => $visit->admit_source_text,
            'visit_date' => $visit->visit_date?->toDateString(),
        ]);
        $this->assertSame(self::DIAGNOSES, array_column($visit->encounter_diagnoses ?? [], 'name'));
        $this->assertSame(['J18.9', self::ICD10], [$visit->encounter_diagnoses[0]['code'] ?? null, $visit->encounter_diagnoses[0]['code_system'] ?? null]);
    }

    /** @return array<string, mixed> */
    private function inpatientBundle(): array
    {
        return ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['fullUrl' => 'urn:uuid:encounter-synthetic-stay', 'resource' => [
                'resourceType' => 'Encounter',
                'id' => 'synthetic-stay',
                'contained' => [
                    ['resourceType' => 'Condition', 'id' => 'dx-pneumonia', 'code' => ['text' => 'Community-acquired pneumonia', 'coding' => [['system' => self::ICD10, 'code' => 'J18.9']]]],
                    ['resourceType' => 'Condition', 'id' => 'dx-hypokalemia', 'code' => ['text' => 'Hypokalemia']],
                ],
                'status' => 'in-progress',
                'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP', 'display' => 'inpatient encounter'],
                'type' => [['text' => 'Synthetic hospital admission']],
                'period' => ['start' => '2026-02-09T14:20:00-08:00'],
                'reasonCode' => [['text' => 'Synthetic chief complaint']],
                // Listed out of order: rank decides.
                'diagnosis' => [
                    ['condition' => ['reference' => '#dx-hypokalemia'], 'rank' => 3],
                    ['condition' => ['reference' => '#dx-pneumonia'], 'rank' => 1, 'use' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/diagnosis-role', 'code' => 'AD']]]],
                    ['condition' => ['display' => 'Acute kidney injury'], 'rank' => 2],
                ],
                'hospitalization' => [
                    'admitSource' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/admit-source', 'code' => 'emd', 'display' => 'From accident/emergency department']], 'text' => 'Emergency department'],
                ],
                'location' => [['location' => ['display' => '6 North Telemetry'], 'status' => 'active']],
                'serviceProvider' => ['display' => 'Example General Hospital'],
            ]],
            ['fullUrl' => 'urn:uuid:impression-synthetic-stay', 'resource' => [
                'resourceType' => 'ClinicalImpression',
                'id' => 'synthetic-stay-impression',
                'status' => 'completed',
                'encounter' => ['reference' => 'Encounter/synthetic-stay'],
                'description' => 'Synthetic impression description',
                'summary' => ['text' => 'Synthetic assessment from the impression'],
            ]],
        ]];
    }

    private function dischargeSummary(bool $withEncountersSection): string
    {
        $section = $withEncountersSection ? <<<'XML'
            <component><structuredBody><component><section>
              <templateId root="2.16.840.1.113883.10.20.22.2.22.1" extension="2015-08-01"/>
              <code code="46240-8" codeSystem="2.16.840.1.113883.6.1"/>
              <title>Encounters</title>
              <text>Synthetic encounters</text>
              <entry typeCode="DRIV"><encounter classCode="ENC" moodCode="EVN">
                <templateId root="2.16.840.1.113883.10.20.22.4.49" extension="2015-08-01"/>
                <id root="2.25.410410" extension="synthetic-stay"/>
                <code nullFlavor="OTH"><originalText>Synthetic hospital admission</originalText></code>
                <effectiveTime><low value="20260209142000-0800"/></effectiveTime>
                <entryRelationship typeCode="COMP"><act classCode="ACT" moodCode="EVN">
                  <templateId root="2.16.840.1.113883.10.20.22.4.80" extension="2024-05-01"/>
                  <code code="29308-4" codeSystem="2.16.840.1.113883.6.1"/>
                  <entryRelationship typeCode="SUBJ"><observation classCode="OBS" moodCode="EVN"><templateId root="2.16.840.1.113883.10.20.22.4.4" extension="2024-05-01"/><value xsi:type="CD" code="J18.9" codeSystem="2.16.840.1.113883.6.90" displayName="Community-acquired pneumonia"/></observation></entryRelationship>
                  <entryRelationship typeCode="SUBJ"><observation classCode="OBS" moodCode="EVN"><templateId root="2.16.840.1.113883.10.20.22.4.4" extension="2024-05-01"/><value xsi:type="CD" nullFlavor="OTH"><originalText>Acute kidney injury</originalText></value></observation></entryRelationship>
                  <entryRelationship typeCode="SUBJ"><observation classCode="OBS" moodCode="EVN"><templateId root="2.16.840.1.113883.10.20.22.4.4" extension="2024-05-01"/><value xsi:type="CD" code="E87.6" codeSystem="2.16.840.1.113883.6.90" displayName="Hypokalemia"/></observation></entryRelationship>
                </act></entryRelationship>
              </encounter></entry>
            </section></component></structuredBody></component>
            XML : '<component><structuredBody><component><section><title>Synthetic Note</title><text>Synthetic text</text></section></component></structuredBody></component>';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:sdtc="urn:hl7-org:sdtc">
              <id root="2.25.4100"/>
              <title>Synthetic Discharge Summary</title>
              <componentOf>
                <encompassingEncounter>
                  <id root="2.25.410410" extension="synthetic-stay"/>
                  <code code="IMP" codeSystem="2.16.840.1.113883.5.4" displayName="inpatient encounter"/>
                  <effectiveTime><low value="20260209142000-0800"/><high value="20260214110000-0800"/></effectiveTime>
                  <sdtc:admissionReferralSourceCode nullFlavor="OTH"><originalText>Emergency department</originalText></sdtc:admissionReferralSourceCode>
                  <dischargeDispositionCode code="01" codeSystem="2.16.840.1.113883.6.301.5" displayName="Discharged to home or self care (routine discharge)"><originalText>Home with family</originalText></dischargeDispositionCode>
                  <responsibleParty><assignedEntity><id root="2.25.4101"/><assignedPerson><name><given>Alex</given><family>Attending</family><suffix>MD</suffix></name></assignedPerson></assignedEntity></responsibleParty>
                  <location><healthCareFacility><id root="2.25.4102"/>
                    <location><name>6 North Telemetry</name></location>
                    <serviceProviderOrganization><name>Example General Hospital</name></serviceProviderOrganization>
                  </healthCareFacility></location>
                </encompassingEncounter>
              </componentOf>
              {$section}
            </ClinicalDocument>
            XML;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mcpVisit(PhrPatient $patient, array $overrides): array
    {
        return [
            'patient_id' => $patient->id,
            'external_id' => 'synthetic-stay-410',
            'source_document_id' => null,
            'expected_version' => null,
            'data' => [
                'visit_date' => '2026-02-09',
                'visit_started_at' => '2026-02-09T22:20:00Z',
                'visit_type' => 'Synthetic hospital admission',
                'facility_name' => 'Example General Hospital',
                'encounter_category' => 'clinical',
                'encounter_class' => 'IMP',
                'encounter_state' => 'in-progress',
                'location' => '6 North Telemetry',
                'admit_source' => 'emd',
                'admit_source_text' => 'Emergency department',
                'encounter_diagnoses' => array_map(static fn (string $name): array => ['name' => $name], self::DIAGNOSES),
                ...$overrides,
            ],
        ];
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function mcpSession(): array
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $client = Client::query()->create([
            'name' => 'Synthetic Encounter Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');

        return [$owner, $patient];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $tool, array $arguments): array
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);

        return $response->json('result') ?? $this->fail('No result: '.$response->getContent());
    }

    /** @param  array<string, mixed>  $message */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers)->assertOk();
    }

    /** @return array<string, mixed> */
    private function fhirExport(PhrPatient $patient): array
    {
        return json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $attributes */
    private function confirmedVisit(PhrPatient $patient, array $attributes): PhrOfficeVisit
    {
        return PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            ...$attributes,
        ]);
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
        Carbon::setTestNow('2026-03-01 12:00:00');

        return $patient;
    }
}
