<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Services\PHR\Encounters\PhrEncounterLinks;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * The records made during an encounter (#411): link tables, FHIR and C-CDA import of the
 * encounter references, FHIR export of them, and the REST/MCP link, unlink and grouped
 * reads. Every value is the synthetic inpatient stay of #405; no real data.
 */
final class PhrEncounterLinksTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const string STAY = 'Encounter/synthetic-stay';

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_a_synthetic_stay_bundle_imports_with_every_record_linked_to_its_encounter(): void
    {
        $patient = $this->patient();

        $result = app(PhrFhirImporter::class)->importBundle($this->stayBundle(), $patient, $patient->owner_user_id);

        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(self::STAY, $visit->external_id);
        $this->assertSame([
            'lab_results' => ['Observation/lab-creatinine', 'Observation/fullUrl:urn:uuid:lab-potassium'],
            'vitals' => ['Observation/vital-heart-rate'],
            'medications' => ['MedicationStatement/ceftriaxone'],
            'procedures' => ['Procedure/chest-radiograph'],
            'documents' => ['DocumentReference/progress-note'],
        ], $this->linkedExternalIds($visit));

        // A reference to an encounter outside the bundle is never guessed at: the procedure
        // stays unlinked, the reference stays in its raw source, and the import says so.
        $elsewhere = PhrProcedure::query()->where('patient_id', $patient->id)->where('external_id', 'Procedure/procedure-elsewhere')->sole();
        $this->assertSame([], app(PhrEncounterLinks::class)->encounterIds((int) $patient->id, 'procedure', [$elsewhere->id]));
        $this->assertSame('Encounter/other-hospital-stay', json_decode((string) $elsewhere->raw_text, true, flags: JSON_THROW_ON_ERROR)['encounter']['reference']);
        $this->assertStringContainsString('1 resource(s) name an encounter that is not', implode("\n", $result->warnings));

        // A link is evidence: the records stay pending review, and a second import changes nothing.
        $this->assertSame(PhrReviewStatus::PENDING, PhrLabResult::query()->where('external_id', 'Observation/lab-creatinine')->sole()->review_status);
        $again = app(PhrFhirImporter::class)->importBundle($this->stayBundle(), $patient, $patient->owner_user_id);
        $this->assertSame([0, 0], [$again->created, $again->updated]);
        $this->assertSame(6, $this->linkCount());
    }

    public function test_links_export_as_encounter_references_and_read_back_as_the_same_links(): void
    {
        $patient = $this->patient();
        app(PhrFhirImporter::class)->importBundle($this->stayBundle(), $patient, $patient->owner_user_id);
        foreach ([PhrOfficeVisit::class, PhrLabResult::class, PhrPatientVital::class, PhrMedication::class, PhrProcedure::class] as $model) {
            $model::query()->where('patient_id', $patient->id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        }
        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $reference = ['reference' => 'Encounter/phr-visit-'.$visit->id];

        $exported = $this->fhirExport($patient);
        $byExternal = static fn (string $type, string $name) => collect($exported['entry'])->first(
            static fn (array $entry): bool => $entry['resource']['resourceType'] === $type && str_contains(json_encode($entry['resource'], JSON_THROW_ON_ERROR), $name),
        )['resource'] ?? null;
        $this->assertSame($reference, $byExternal('Observation', 'Creatinine')['encounter']);
        $this->assertSame($reference, $byExternal('Observation', 'Heart rate')['encounter']);
        $this->assertSame($reference, $byExternal('Procedure', 'Synthetic chest radiograph')['encounter']);
        $this->assertSame($reference, $byExternal('MedicationStatement', 'ceftriaxone')['context']);
        $this->assertSame(['encounter' => [$reference]], $byExternal('DocumentReference', 'Daily Progress Note')['context']);
        $this->assertArrayNotHasKey('encounter', $byExternal('Procedure', 'Synthetic outside procedure'), 'an unlinked record carries no reference');

        // PHR's own export reads back into another patient with every link in place.
        $copy = $this->patient('Pat Example Copy');
        app(PhrFhirImporter::class)->importBundle($exported, $copy, $copy->owner_user_id);
        $copied = PhrOfficeVisit::query()->where('patient_id', $copy->id)->sole();
        $this->assertSame(
            ['lab_results' => 2, 'vitals' => 1, 'medications' => 1, 'procedures' => 1, 'documents' => 1],
            app(PhrEncounterLinks::class)->counts((int) $copy->id, [$copied->id])[$copied->id],
        );
    }

    public function test_a_lab_linked_to_a_pending_or_retracted_visit_exports_without_the_reference(): void
    {
        $patient = $this->patient();
        $visit = PhrOfficeVisit::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'visit_date' => '2026-02-09', 'review_status' => PhrReviewStatus::PENDING]);
        $lab = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'test_name' => 'Creatinine', 'analyte' => 'Creatinine', 'value' => '1.8', 'review_status' => PhrReviewStatus::CONFIRMED]);
        app(PhrEncounterLinks::class)->link((int) $patient->id, (int) $visit->id, 'lab-result', (int) $lab->id);

        $observation = $this->exportedLab($patient);
        $this->assertArrayNotHasKey('encounter', $observation);

        $visit->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $this->assertSame(['reference' => 'Encounter/phr-visit-'.$visit->id], $this->exportedLab($patient)['encounter']);

        $visit->forceFill(['retracted_at' => now()])->save();
        $this->assertArrayNotHasKey('encounter', $this->exportedLab($patient));
    }

    public function test_an_agent_links_and_unlinks_records_over_mcp_without_touching_their_version_or_review(): void
    {
        [$owner, $patient] = $this->mcpSession();
        $visit = $this->visit($patient);
        $other = $this->visit($patient, '2025-11-03');
        $lab = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'test_name' => 'Potassium', 'analyte' => 'Potassium', 'value' => '3.2', 'review_status' => PhrReviewStatus::PENDING]);
        $document = PhrDocument::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'title' => 'Daily Progress Note', 'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0]);
        $before = $this->tool('clinical.get', ['resource_type' => 'lab-results', 'patient_id' => $patient->id, 'record_id' => $lab->id])['structuredContent']['data'];
        $this->assertNull($before['encounter_id']);

        $linked = $this->tool('office_visits.link_record', ['patient_id' => $patient->id, 'visit_id' => $visit->id, 'resource_type' => 'lab-result', 'resource_id' => $lab->id]);
        $this->assertFalse($linked['isError'] ?? false, json_encode($linked));
        $this->assertSame('linked', $linked['structuredContent']['outcome']);
        $this->assertSame('unchanged', $this->tool('office_visits.link_record', ['patient_id' => $patient->id, 'visit_id' => $visit->id, 'resource_type' => 'lab-result', 'resource_id' => $lab->id])['structuredContent']['outcome']);

        $after = $this->tool('clinical.get', ['resource_type' => 'lab-results', 'patient_id' => $patient->id, 'record_id' => $lab->id])['structuredContent']['data'];
        $this->assertSame($visit->id, $after['encounter_id']);
        $this->assertSame($before['version'], $after['version'], 'a link is evidence, not a change to the record');
        $this->assertSame(PhrReviewStatus::PENDING, $after['review_status']);
        $listed = $this->tool('clinical.list', ['resource_type' => 'lab-results', 'patient_id' => $patient->id])['structuredContent']['data'];
        $this->assertSame($visit->id, $listed[0]['encounter_id']);

        // A record belongs to one encounter: moving it is an unlink and a link.
        $refused = $this->tool('office_visits.link_record', ['patient_id' => $patient->id, 'visit_id' => $other->id, 'resource_type' => 'lab-result', 'resource_id' => $lab->id]);
        $this->assertTrue($refused['isError']);
        $this->assertSame(1, DB::table('phr_office_visit_lab_results')->count());

        $this->assertSame('linked', $this->tool('office_visits.link_record', ['patient_id' => $patient->id, 'visit_id' => $visit->id, 'resource_type' => 'document', 'resource_id' => $document->id])['structuredContent']['outcome']);
        $this->assertSame($visit->id, $this->tool('documents.get', ['patient_id' => $patient->id, 'document_id' => $document->id])['structuredContent']['data']['encounter_id']);

        $visitRead = $this->tool('clinical.get', ['resource_type' => 'office-visits', 'patient_id' => $patient->id, 'record_id' => $visit->id])['structuredContent']['data'];
        $this->assertSame(['lab_results' => 1, 'vitals' => 0, 'medications' => 0, 'procedures' => 0, 'documents' => 1], $visitRead['linked_records']);
        $evidence = $this->tool('evidence.links', ['patient_id' => $patient->id, 'resource_type' => 'office-visit', 'resource_id' => $visit->id])['structuredContent']['data'];
        $this->assertContains(['target_type' => 'lab-result', 'target_id' => $lab->id], $evidence);
        $this->assertContains(['target_type' => 'document', 'target_id' => $document->id], $evidence);
        $back = $this->tool('evidence.links', ['patient_id' => $patient->id, 'resource_type' => 'lab-result', 'resource_id' => $lab->id])['structuredContent']['data'];
        $this->assertSame([['target_type' => 'office-visit', 'target_id' => $visit->id]], $back);

        $stay = $this->tool('office_visits.get_stay', ['patient_id' => $patient->id, 'visit_id' => $visit->id]);
        $this->assertFalse($stay['isError'] ?? false, json_encode($stay));
        $groups = $stay['structuredContent']['data']['groups'];
        $this->assertSame([$lab->id], array_column($groups['lab_results']['data'], 'id'));
        $this->assertSame($visit->id, $groups['lab_results']['data'][0]['encounter_id']);
        $this->assertSame([$document->id], array_column($groups['documents']['data'], 'id'));
        $this->assertSame([], $groups['vitals']['data']);

        $unlinked = $this->tool('office_visits.unlink_record', ['patient_id' => $patient->id, 'visit_id' => $visit->id, 'resource_type' => 'lab-result', 'resource_id' => $lab->id]);
        $this->assertSame('unlinked', $unlinked['structuredContent']['outcome']);
        $this->assertSame('unchanged', $this->tool('office_visits.unlink_record', ['patient_id' => $patient->id, 'visit_id' => $visit->id, 'resource_type' => 'lab-result', 'resource_id' => $lab->id])['structuredContent']['outcome']);
        $this->assertSame('linked', $this->tool('office_visits.link_record', ['patient_id' => $patient->id, 'visit_id' => $other->id, 'resource_type' => 'lab-result', 'resource_id' => $lab->id])['structuredContent']['outcome']);
        $this->assertSame($before['version'], $this->tool('clinical.get', ['resource_type' => 'lab-results', 'patient_id' => $patient->id, 'record_id' => $lab->id])['structuredContent']['data']['version']);
    }

    public function test_a_record_belongs_to_at_most_one_encounter_in_the_database_too(): void
    {
        $patient = $this->patient();
        $first = $this->visit($patient);
        $second = $this->visit($patient, '2025-11-03');
        $vital = PhrPatientVital::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'vital_name' => 'Heart rate', 'vital_value' => '88']);
        app(PhrEncounterLinks::class)->link((int) $patient->id, (int) $first->id, 'vital', (int) $vital->id);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('phr_office_visit_vitals')->insert(['patient_id' => $patient->id, 'office_visit_id' => $second->id, 'vital_id' => $vital->id]);
    }

    public function test_a_link_to_a_withdrawn_visit_gives_way_and_an_import_never_moves_a_placed_record(): void
    {
        $patient = $this->patient();
        $withdrawn = $this->visit($patient, '2025-11-03');
        $stay = $this->visit($patient);
        $procedure = PhrProcedure::query()->create(['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'name' => 'Synthetic chest radiograph', 'status' => 'completed']);
        $links = app(PhrEncounterLinks::class);
        $links->link((int) $patient->id, (int) $withdrawn->id, 'procedure', (int) $procedure->id);
        $withdrawn->forceFill(['retracted_at' => now()])->save();

        $this->assertSame([], $links->encounterIds((int) $patient->id, 'procedure', [$procedure->id]), 'a withdrawn visit is no record\'s encounter');
        $this->assertFalse($links->linkIfFree((int) $patient->id, (int) $stay->id, 'procedure', (int) $procedure->id), 'an import leaves the link alone');
        $this->assertSame(PhrEncounterLinks::LINKED, $links->link((int) $patient->id, (int) $stay->id, 'procedure', (int) $procedure->id));
        $this->assertSame([$procedure->id => $stay->id], $links->encounterIds((int) $patient->id, 'procedure', [$procedure->id]));
    }

    public function test_the_grouped_read_pages_each_group_on_its_own_and_document_links_need_documents_read(): void
    {
        [$owner, $patient] = $this->restSession([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $visit = $this->visit($patient);
        $links = app(PhrEncounterLinks::class);
        $ids = [];
        foreach (['Creatinine', 'Potassium', 'Sodium'] as $analyte) {
            $lab = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'test_name' => 'BMP', 'analyte' => $analyte, 'value' => '1', 'review_status' => PhrReviewStatus::PENDING]);
            $links->link((int) $patient->id, (int) $visit->id, 'lab-result', (int) $lab->id);
            $ids[] = $lab->id;
        }
        $document = PhrDocument::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'title' => 'Plan of Care', 'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0]);
        $links->link((int) $patient->id, (int) $visit->id, 'document', (int) $document->id);
        $url = "/api/v1/patients/{$patient->id}/office-visits/{$visit->id}/stay";

        $first = $this->getJson($url.'?limit=2')->assertOk();
        $this->assertSame(array_slice($ids, 0, 2), array_column($first->json('data.groups.lab_results.data'), 'id'));
        $this->assertTrue($first->json('data.groups.lab_results.pagination.has_more'));
        $this->assertNull($first->json('data.groups.documents'), 'documents need documents:read');
        $this->assertSame(1, $first->json('data.office_visit.linked_records.documents'));
        $this->assertSame(3, $first->json('data.office_visit.linked_records.lab_results'));

        $cursor = (string) $first->json('data.groups.lab_results.pagination.next_cursor');
        $next = $this->getJson($url.'?group=lab_results&limit=2&cursor='.urlencode($cursor))->assertOk();
        $this->assertSame([$ids[2]], array_column($next->json('data.groups.lab_results.data'), 'id'));
        $this->assertSame(['lab_results'], array_keys($next->json('data.groups')));
        $this->getJson($url.'?cursor='.urlencode($cursor))->assertUnprocessable();

        $this->postJson("/api/v1/patients/{$patient->id}/office-visits/{$visit->id}/links", ['resource_type' => 'document', 'resource_id' => $document->id])->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/office-visits/{$visit->id}/links/document/{$document->id}")->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/office-visits/{$visit->id}/links/lab-result/{$ids[0]}")->assertOk()->assertJsonPath('outcome', 'unlinked');
    }

    public function test_a_ccda_documents_encompassing_encounter_takes_the_records_it_dates_inside_the_stay(): void
    {
        $patient = $this->patient();

        $result = app(PhrCcdaImporter::class)->importXml($this->dischargeSummary(), $patient, $patient->owner_user_id);

        $visit = PhrOfficeVisit::query()->where('patient_id', $patient->id)->sole();
        $inside = PhrLabResult::query()->where('patient_id', $patient->id)->where('analyte', 'Creatinine')->sole();
        $outside = PhrLabResult::query()->where('patient_id', $patient->id)->where('analyte', 'Synthetic old result')->sole();
        $links = app(PhrEncounterLinks::class);
        $this->assertSame([$inside->id => $visit->id], $links->encounterIds((int) $patient->id, 'lab-result', [$inside->id, $outside->id]));
        $this->assertStringContainsString('1 record(s) are dated outside', implode("\n", $result->warnings));

        // A CCD is a longitudinal summary: its header encounter takes none of its history.
        $summary = $this->patient('Pat Example Summary');
        app(PhrCcdaImporter::class)->importXml($this->dischargeSummary(ccd: true), $summary, $summary->owner_user_id);
        $this->assertSame(1, PhrOfficeVisit::query()->where('patient_id', $summary->id)->count());
        $this->assertSame(0, DB::table('phr_office_visit_lab_results')->where('patient_id', $summary->id)->count());
    }

    public function test_the_visit_page_lists_the_records_made_during_it(): void
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $visit = $this->visit($patient);
        $lab = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'test_name' => 'BMP', 'analyte' => 'Creatinine', 'value' => '1.8', 'unit' => 'mg/dL', 'abnormal_flag' => 'H', 'review_status' => PhrReviewStatus::PENDING]);
        $retracted = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'test_name' => 'BMP', 'analyte' => 'Withdrawn', 'value' => '1', 'review_status' => PhrReviewStatus::PENDING]);
        app(PhrEncounterLinks::class)->link((int) $patient->id, (int) $visit->id, 'lab-result', (int) $lab->id);
        app(PhrEncounterLinks::class)->link((int) $patient->id, (int) $visit->id, 'lab-result', (int) $retracted->id);
        $retracted->forceFill(['retracted_at' => now()])->save();

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patient->id}/office-visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('office_visit.linked_records.lab_results.0.name', 'Creatinine')
            ->assertJsonPath('office_visit.linked_records.lab_results.0.value', '1.8')
            ->assertJsonCount(1, 'office_visit.linked_records.lab_results')
            ->assertJsonPath('office_visit.linked_records.documents', []);
    }

    /** @return array<string, list<string>> group => the external ids of the records linked to the visit */
    private function linkedExternalIds(PhrOfficeVisit $visit): array
    {
        $linked = [];
        foreach (PhrEncounterLinks::TYPES as $type => $definition) {
            $linked[$definition['group']] = app(PhrEncounterLinks::class)->linkedRecords((int) $visit->patient_id, (int) $visit->id, $type)
                ->orderBy('id')->pluck('external_id')->all();
        }

        return [
            'lab_results' => $linked['lab_results'],
            'vitals' => $linked['vitals'],
            'medications' => $linked['medications'],
            'procedures' => $linked['procedures'],
            'documents' => $linked['documents'],
        ];
    }

    private function linkCount(): int
    {
        return array_sum(array_map(static fn (array $definition): int => DB::table($definition['table'])->count(), PhrEncounterLinks::TYPES));
    }

    /** @return array<string, mixed> */
    private function stayBundle(): array
    {
        $byId = ['reference' => self::STAY];
        $laboratory = [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'laboratory']]]];

        return ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['fullUrl' => 'urn:uuid:encounter-synthetic-stay', 'resource' => [
                'resourceType' => 'Encounter', 'id' => 'synthetic-stay', 'status' => 'in-progress',
                'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP'],
                'type' => [['text' => 'Synthetic hospital admission']],
                'period' => ['start' => '2026-02-09T14:20:00-08:00'],
                'location' => [['location' => ['display' => '6 North Telemetry']]],
                'serviceProvider' => ['display' => 'Example General Hospital'],
            ]],
            ['fullUrl' => 'urn:uuid:lab-creatinine', 'resource' => [
                'resourceType' => 'Observation', 'id' => 'lab-creatinine', 'status' => 'final', 'category' => $laboratory,
                'code' => ['text' => 'Creatinine', 'coding' => [['system' => 'http://loinc.org', 'code' => '2160-0']]],
                'effectiveDateTime' => '2026-02-11T06:00:00-08:00', 'valueQuantity' => ['value' => 1.8, 'unit' => 'mg/dL'],
                'interpretation' => [['text' => 'H']], 'encounter' => $byId,
            ]],
            // No id: named by its fullUrl, and its encounter named by the encounter's fullUrl.
            ['fullUrl' => 'urn:uuid:lab-potassium', 'resource' => [
                'resourceType' => 'Observation', 'status' => 'final', 'category' => $laboratory,
                'code' => ['text' => 'Potassium', 'coding' => [['system' => 'http://loinc.org', 'code' => '2823-3']]],
                'effectiveDateTime' => '2026-02-11T06:00:00-08:00', 'valueQuantity' => ['value' => 3.2, 'unit' => 'mmol/L'],
                'interpretation' => [['text' => 'L']], 'encounter' => ['reference' => 'urn:uuid:encounter-synthetic-stay'],
            ]],
            ['resource' => [
                'resourceType' => 'Observation', 'id' => 'vital-heart-rate', 'status' => 'final',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs']]]],
                'code' => ['text' => 'Heart rate'], 'effectiveDateTime' => '2026-02-11T08:00:00-08:00',
                'valueQuantity' => ['value' => 88, 'unit' => '/min'], 'encounter' => $byId,
            ]],
            ['resource' => [
                'resourceType' => 'DocumentReference', 'id' => 'progress-note', 'status' => 'current',
                'type' => ['text' => 'Progress note', 'coding' => [['system' => 'http://loinc.org', 'code' => '11506-3']]],
                'description' => 'Daily Progress Note',
                'content' => [['attachment' => ['contentType' => 'text/markdown', 'title' => 'Daily Progress Note']]],
                'context' => ['encounter' => [$byId]],
            ]],
            ['resource' => [
                'resourceType' => 'Procedure', 'id' => 'chest-radiograph', 'status' => 'completed',
                'code' => ['text' => 'Synthetic chest radiograph'], 'performedDateTime' => '2026-02-10T10:00:00-08:00',
                'encounter' => $byId,
            ]],
            ['resource' => [
                'resourceType' => 'MedicationStatement', 'id' => 'ceftriaxone', 'status' => 'active',
                'medicationCodeableConcept' => ['text' => 'ceftriaxone 1 g IV every 24 hours'],
                'effectivePeriod' => ['start' => '2026-02-09'], 'context' => $byId,
            ]],
            ['resource' => [
                'resourceType' => 'Procedure', 'id' => 'procedure-elsewhere', 'status' => 'completed',
                'code' => ['text' => 'Synthetic outside procedure'], 'performedDateTime' => '2025-11-04T06:00:00-08:00',
                'encounter' => ['reference' => 'Encounter/other-hospital-stay'],
            ]],
        ]];
    }

    private function dischargeSummary(bool $ccd = false): string
    {
        $template = $ccd ? '<templateId root="2.16.840.1.113883.10.20.22.1.2"/>' : '<templateId root="2.16.840.1.113883.10.20.22.1.8"/>';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
              <templateId root="2.16.840.1.113883.10.20.22.1.1"/>
              {$template}
              <id root="2.25.4110"/>
              <title>Synthetic Discharge Summary</title>
              <componentOf>
                <encompassingEncounter>
                  <id root="2.25.411411" extension="synthetic-stay"/>
                  <code code="IMP" codeSystem="2.16.840.1.113883.5.4" displayName="inpatient encounter"/>
                  <effectiveTime><low value="20260209142000-0800"/><high value="20260214110000-0800"/></effectiveTime>
                  <location><healthCareFacility><id root="2.25.4112"/>
                    <location><name>6 North Telemetry</name></location>
                    <serviceProviderOrganization><name>Example General Hospital</name></serviceProviderOrganization>
                  </healthCareFacility></location>
                </encompassingEncounter>
              </componentOf>
              <component><structuredBody><component><section>
                <templateId root="2.16.840.1.113883.10.20.22.2.3.1"/>
                <code code="30954-2" codeSystem="2.16.840.1.113883.6.1"/>
                <title>Results</title>
                <text>Synthetic results</text>
                <entry typeCode="DRIV"><organizer classCode="BATTERY" moodCode="EVN">
                  <templateId root="2.16.840.1.113883.10.20.22.4.1"/>
                  <id root="2.25.4113"/>
                  <code code="51990-0" codeSystem="2.16.840.1.113883.6.1" displayName="Basic metabolic panel"/>
                  <statusCode code="completed"/>
                  <component><observation classCode="OBS" moodCode="EVN">
                    <templateId root="2.16.840.1.113883.10.20.22.4.2"/>
                    <id root="2.25.4114" extension="creatinine"/>
                    <code code="2160-0" codeSystem="2.16.840.1.113883.6.1" displayName="Creatinine"/>
                    <statusCode code="completed"/>
                    <effectiveTime value="20260211060000-0800"/>
                    <value xsi:type="PQ" value="1.8" unit="mg/dL"/>
                  </observation></component>
                  <component><observation classCode="OBS" moodCode="EVN">
                    <templateId root="2.16.840.1.113883.10.20.22.4.2"/>
                    <id root="2.25.4114" extension="old-result"/>
                    <code code="SYN-1" codeSystem="2.25.9" displayName="Synthetic old result"/>
                    <statusCode code="completed"/>
                    <effectiveTime value="20250601060000-0800"/>
                    <value xsi:type="PQ" value="1" unit="mg/dL"/>
                  </observation></component>
                </organizer></entry>
              </section></component></structuredBody></component>
            </ClinicalDocument>
            XML;
    }

    /** @return array<string, mixed> */
    private function exportedLab(PhrPatient $patient): array
    {
        $exported = $this->fhirExport($patient);

        return collect($exported['entry'])->first(static fn (array $entry): bool => str_starts_with((string) $entry['resource']['id'], 'phr-lab-'))['resource'];
    }

    /** @return array<string, mixed> */
    private function fhirExport(PhrPatient $patient): array
    {
        return json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function visit(PhrPatient $patient, string $date = '2026-02-09'): PhrOfficeVisit
    {
        return PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'visit_date' => $date,
            'visit_type' => 'Synthetic hospital admission',
            'encounter_class' => 'IMP',
            'review_status' => PhrReviewStatus::PENDING,
        ]);
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function mcpSession(): array
    {
        [$owner, $patient] = $this->restSession([AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::DOCUMENTS_READ]);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');

        return [$owner, $patient];
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: User, 1: PhrPatient}
     */
    private function restSession(array $scopes): array
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $client = Client::query()->create([
            'name' => 'Synthetic Encounter Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($owner, $scopes, 'api', $client);

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
