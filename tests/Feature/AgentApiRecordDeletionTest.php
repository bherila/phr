<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationTransition;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrRespiratoryEvent;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentRecordSearchCatalog;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * Agents delete what a person could delete (#441): the browser's own delete, for a
 * record whoever wrote it, by its current version, under scopes granted apart from
 * writing. Every value is synthetic.
 */
final class AgentApiRecordDeletionTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    /** The MCP endpoint listing every toolset, not only the core tools. */
    private const string ALL_TOOLSETS = '/api/v1/mcp?toolsets=all';

    private const string STALE = '0000000000000000000000000000000000000000000000000000000000000000';

    private Client $client;

    private int $nextId = 10;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
        // The browser's delete and the agent's are compared row for row, so both
        // run at the same instant.
        $this->travelTo(Carbon::parse('2026-03-04 05:06:07'));
        $this->client = Client::query()->create([
            'name' => 'Synthetic Deletion Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }

    /**
     * Every writable clinical type, with the smallest record a person creates in
     * the browser (transitions have no browser create, so the test writes one).
     *
     * @return array<string, array{string, string|null, array<string, mixed>}>
     */
    public static function clinicalResources(): array
    {
        return [
            'allergies' => ['allergies', 'allergy', ['substance' => 'Synthetic substance']],
            'conditions' => ['conditions', 'condition', ['name' => 'Synthetic condition']],
            'immunizations' => ['immunizations', 'immunization', ['vaccine_name' => 'Synthetic vaccine']],
            'lab-results' => ['lab-results', 'lab_result', ['analyte' => 'Synthetic analyte']],
            'medications' => ['medications', 'medication', ['name' => 'Synthetic medication']],
            'medication-fills' => ['medication-fills', 'medication_fill', ['filled_on' => '2026-01-15', 'quantity' => '30', 'drug_as_printed' => 'Synthetic drug 10 mg tab']],
            'office-visits' => ['office-visits', 'office_visit', ['visit_date' => '2026-01-15']],
            'procedures' => ['procedures', 'procedure', ['name' => 'Synthetic procedure']],
            'vitals' => ['vitals', 'vital', ['vital_name' => 'Heart Rate']],
            'episodes' => ['episodes', 'episode', ['title' => 'Synthetic episode']],
            'medication-transitions' => ['medication-transitions', null, []],
        ];
    }

    public function test_every_writable_clinical_type_is_covered(): void
    {
        $covered = array_keys(self::clinicalResources());
        $writable = AgentClinicalResourceCatalog::writableIds();
        sort($covered);
        sort($writable);

        $this->assertSame($writable, $covered);
    }

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('clinicalResources')]
    public function test_an_agent_with_the_delete_scope_deletes_a_record_a_person_created(string $resource, ?string $key, array $payload): void
    {
        [$person, $patient] = $this->person();
        $id = $key === null
            ? (int) $this->transition($patient)->id
            : (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/{$resource}", $payload)->assertCreated()->json("{$key}.id");
        $medicationId = $resource === 'medication-fills' ? PhrMedicationFill::query()->findOrFail($id)->medication_id : null;

        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE]);
        $record = "/api/v1/patients/{$patient->id}/{$resource}/{$id}";
        $version = (string) $this->getJson($record)->assertOk()->json('data.version');

        $deleted = $this->assertDeletesAsTheBrowserDoes(
            $person,
            "/api/phr/patients/{$patient->id}/{$resource}/{$id}",
            $record,
            $version,
            [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE],
        );

        $this->assertSame($resource, $deleted['resource_type']);
        $this->assertSame(['resource_type' => $resource, 'id' => $id, 'patient_id' => $patient->id, 'change_type' => 'deleted', 'changed_at' => now()->toIso8601String()], $deleted['data']);
        $this->getJson($record)->assertNotFound();
        $this->assertNotNull(AgentClinicalResourceCatalog::definition($resource)['model']::withTrashed()->findOrFail($id)->deleted_at);
        if ($medicationId !== null) {
            // The medication the browser fill created for it went with it, as in the browser.
            $this->assertNull(PhrMedication::query()->find($medicationId));
        }
        $this->assertTombstoneInChanges($patient, $resource, $deleted['data']);
    }

    public function test_an_agent_deletes_a_document_a_person_uploaded(): void
    {
        [$person, $patient] = $this->person();
        $id = (int) $this->actingAs($person)->post("/api/phr/patients/{$patient->id}/documents", [
            'document_type' => 'other',
            'file' => UploadedFile::fake()->createWithContent('synthetic note.pdf', '%PDF-1.4 synthetic deletion'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

        $scopes = [AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_DELETE, AgentApiScopes::CLINICAL_READ];
        $this->agent($person, $scopes);
        $record = "/api/v1/patients/{$patient->id}/documents/{$id}";
        $version = (string) $this->getJson($record)->assertOk()->json('data.version');

        $deleted = $this->assertDeletesAsTheBrowserDoes($person, "/api/phr/patients/{$patient->id}/documents/{$id}", $record, $version, $scopes);

        $this->assertSame('document', $deleted['resource_type']);
        $this->assertSame('documents', $deleted['data']['resource_type']);
        // Audited like every agent write: the route, the method, the outcome, never the body.
        $this->assertTrue(DB::table('agent_api_audits')
            ->where('route_name', 'agent-api.v1.documents.destroy')
            ->where('http_method', 'DELETE')
            ->where('response_status', 200)
            ->where('actor_user_id', $person->id)
            ->exists());
        $this->getJson($record)->assertNotFound();
        $this->assertNotNull(PhrDocument::withTrashed()->findOrFail($id)->deleted_at);
        $this->assertTombstoneInChanges($patient, 'documents', $deleted['data']);
    }

    public function test_deleting_a_medication_a_transition_names_leaves_the_transition_as_the_browser_does(): void
    {
        [$person, $patient] = $this->person();
        $transition = $this->transition($patient);
        $medicationId = (int) $transition->home_medication_id;

        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE]);
        $record = "/api/v1/patients/{$patient->id}/medications/{$medicationId}";
        $version = (string) $this->getJson($record)->assertOk()->json('data.version');
        $this->assertDeletesAsTheBrowserDoes($person, "/api/phr/patients/{$patient->id}/medications/{$medicationId}", $record, $version, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE]);

        // A transition records what happened to the medication during the stay; a
        // person removing the medication does not rewrite that account.
        $this->getJson("/api/v1/patients/{$patient->id}/medication-transitions/{$transition->id}")
            ->assertOk()
            ->assertJsonPath('data.home_medication_id', $medicationId);
        $this->assertNotNull(PhrMedication::withTrashed()->findOrFail($medicationId)->deleted_at);
    }

    public function test_deleting_an_encounter_with_linked_records_leaves_them_as_the_browser_does(): void
    {
        [$person, $patient] = $this->person();
        $visitId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/office-visits", ['visit_date' => '2026-01-15'])->assertCreated()->json('office_visit.id');
        $labId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/lab-results", ['analyte' => 'Synthetic analyte'])->assertCreated()->json('lab_result.id');
        $episodeId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/episodes", ['title' => 'Synthetic episode'])->assertCreated()->json('episode.id');
        $scopes = [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::CLINICAL_DELETE];
        $this->agent($person, $scopes);
        $this->postJson("/api/v1/patients/{$patient->id}/office-visits/{$visitId}/links", ['resource_type' => 'lab-result', 'resource_id' => $labId])->assertOk();
        $this->postJson("/api/v1/patients/{$patient->id}/episodes/{$episodeId}/encounters", ['office_visit_id' => $visitId])->assertOk();

        $record = "/api/v1/patients/{$patient->id}/office-visits/{$visitId}";
        $version = (string) $this->getJson($record)->assertOk()->json('data.version');
        $this->assertDeletesAsTheBrowserDoes($person, "/api/phr/patients/{$patient->id}/office-visits/{$visitId}", $record, $version, $scopes);

        $this->getJson("/api/v1/patients/{$patient->id}/lab-results/{$labId}")->assertOk();
        $this->getJson("/api/v1/patients/{$patient->id}/episodes/{$episodeId}")->assertOk()->assertJsonPath('data.encounter_ids', []);
    }

    public function test_a_record_retracted_by_its_source_can_still_be_deleted(): void
    {
        [$person, $patient] = $this->person();
        $id = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/allergies", ['substance' => 'Synthetic substance'])->assertCreated()->json('allergy.id');
        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE]);
        $version = (string) $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$id}")->json('data.version');
        $allergy = AgentClinicalResourceCatalog::definition('allergies')['model']::query()->findOrFail($id);
        $allergy->forceFill(['retracted_at' => now()])->save();

        // The withdrawal changed the record, so the version read before it is stale.
        $this->deleteJson("/api/v1/patients/{$patient->id}/allergies/{$id}", ['expected_version' => $version])->assertConflict();
        $current = app(AgentClinicalRecordVersion::class)->for($allergy->refresh());
        $this->deleteJson("/api/v1/patients/{$patient->id}/allergies/{$id}", ['expected_version' => $current])
            ->assertOk()
            ->assertJsonPath('outcome', 'deleted');
    }

    public function test_a_delete_reaches_only_the_patient_in_the_url(): void
    {
        [$person, $patient] = $this->person();
        [, $other] = $this->person();
        PhrPatientUserAccess::query()->create(['patient_id' => $other->id, 'user_id' => $person->id, 'access_level' => PhrPatientUserAccess::LEVEL_MANAGER, 'granted_by_user_id' => $other->owner_user_id, 'granted_at' => now()]);
        $id = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/allergies", ['substance' => 'Synthetic substance'])->assertCreated()->json('allergy.id');
        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE]);
        $version = (string) $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$id}")->json('data.version');

        $this->deleteJson("/api/v1/patients/{$other->id}/allergies/{$id}", ['expected_version' => $version])->assertNotFound();
        $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$id}")->assertOk();
    }

    public function test_a_viewer_cannot_delete(): void
    {
        [, $patient] = $this->person();
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $patient->owner_user_id, 'granted_at' => now()]);
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $id = (int) $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/allergies", ['substance' => 'Synthetic substance'])->assertCreated()->json('allergy.id');
        $this->agent($viewer, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE]);
        $version = (string) $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$id}")->assertOk()->json('data.version');

        $this->deleteJson("/api/v1/patients/{$patient->id}/allergies/{$id}", ['expected_version' => $version])->assertForbidden();
        $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$id}")->assertOk();
    }

    public function test_without_a_delete_scope_rest_refuses_and_existing_scopes_never_imply_it(): void
    {
        [$person, $patient] = $this->person();
        $allergy = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/allergies", ['substance' => 'Synthetic substance'])->assertCreated()->json('allergy.id');
        $log = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'custom'])->assertCreated()->json('health_log.id');
        $entry = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs/{$log}/entries", ['occurred_at' => '2026-03-01T08:30:00Z'])->assertCreated()->json('entry.id');
        $document = (int) $this->actingAs($person)->post("/api/phr/patients/{$patient->id}/documents", [
            'document_type' => 'other',
            'file' => UploadedFile::fake()->createWithContent('synthetic note.pdf', '%PDF-1.4 synthetic scope'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');

        // Everything but the delete scopes, read and write alike.
        $this->agent($person, array_values(array_diff(AgentApiScopes::restIds(), [AgentApiScopes::CLINICAL_DELETE, AgentApiScopes::DOCUMENTS_DELETE])));
        $body = ['expected_version' => self::STALE];
        $this->deleteJson("/api/v1/patients/{$patient->id}/allergies/{$allergy}", $body)->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/documents/{$document}", $body)->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/health-logs/{$log}", $body)->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/health-logs/{$log}/entries/{$entry}", $body)->assertForbidden();
        $this->deleteJson("/api/v1/patients/{$patient->id}/respiratory-events/batch", ['uuids' => ['synthetic-event']])->assertForbidden();

        // clinical:delete is not documents:delete, nor the reverse.
        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::CLINICAL_DELETE]);
        $this->deleteJson("/api/v1/patients/{$patient->id}/documents/{$document}", $body)->assertForbidden();
        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_DELETE]);
        $this->deleteJson("/api/v1/patients/{$patient->id}/allergies/{$allergy}", $body)->assertForbidden();

        // The version comes from a read, so delete alone is not enough.
        $this->agent($person, [AgentApiScopes::CLINICAL_DELETE]);
        $this->deleteJson("/api/v1/patients/{$patient->id}/allergies/{$allergy}", $body)->assertForbidden();

        $this->assertSame(1, AgentClinicalResourceCatalog::definition('allergies')['model']::query()->count());
        $this->assertSame(1, PhrDocument::query()->count());
        $this->assertSame(1, PhrHealthLogEntry::query()->count());
    }

    public function test_without_a_delete_scope_the_delete_tools_are_not_listed(): void
    {
        [$person] = $this->person();
        $deleteTools = ['clinical.delete', 'documents.delete', 'health_logs.delete', 'health_log_entries.delete', 'respiratory_events.delete'];
        $base = [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::PATIENTS_WRITE, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE];

        $tools = $this->toolsFor($person, $base);
        $this->assertSame([], array_values(array_intersect($deleteTools, array_keys($tools))));
        foreach (['patients.update', 'health_logs.update', 'health_log_entries.update'] as $update) {
            $this->assertArrayHasKey($update, $tools);
        }

        $tools = $this->toolsFor($person, [...$base, AgentApiScopes::CLINICAL_DELETE]);
        $this->assertSame(['clinical.delete', 'health_logs.delete', 'health_log_entries.delete', 'respiratory_events.delete'], array_values(array_intersect($deleteTools, array_keys($tools))));

        $tools = $this->toolsFor($person, [...$base, AgentApiScopes::CLINICAL_DELETE, AgentApiScopes::DOCUMENTS_DELETE]);
        foreach ($deleteTools as $name) {
            $this->assertArrayHasKey($name, $tools);
            $this->assertTrue($tools[$name]['annotations']['destructiveHint'], $name);
            $this->assertFalse($tools[$name]['annotations']['readOnlyHint'], $name);
        }
        $this->assertSame(AgentClinicalResourceCatalog::writableIds(), $tools['clinical.delete']['inputSchema']['properties']['resource_type']['enum']);
        $this->assertSame(['expected_version', 'patient_id', 'record_id', 'resource_type'], $this->sorted($tools['clinical.delete']['inputSchema']['required']));
    }

    public function test_patients_update_round_trips_with_a_version_check(): void
    {
        [$person, $patient] = $this->person();
        $this->agent($person, [AgentApiScopes::PATIENTS_READ, AgentApiScopes::PATIENTS_WRITE]);
        $url = "/api/v1/patients/{$patient->id}";
        $version = (string) $this->getJson($url)->assertOk()->json('data.version');

        $this->patchJson($url, ['expected_version' => self::STALE, 'data' => ['notes' => 'Synthetic note']])->assertConflict();
        $this->patchJson($url, ['expected_version' => $version, 'data' => ['display_name' => null]])->assertUnprocessable();
        // Real names decide which chart a document lands on: a person sets them.
        $this->patchJson($url, ['expected_version' => $version, 'data' => ['real_names' => ['Synthetic Name']]])->assertUnprocessable();
        $this->assertNull($patient->refresh()->notes);

        $this->travel(1)->seconds();
        $updated = $this->patchJson($url, ['expected_version' => $version, 'data' => ['notes' => 'Synthetic note', 'relationship' => 'self', 'birth_date' => '1990-02-03']])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->json();
        $this->assertNotSame($version, $updated['version']);
        $this->assertSame($updated['version'], $updated['data']['version']);
        $this->assertSame($this->getJson($url)->assertOk()->json('data'), $updated['data']);
        $this->assertSame('Synthetic note', $updated['data']['notes']);
        $this->assertSame('1990-02-03', $updated['data']['birth_date']);

        // The same change again is a no-op, and the old version is now stale.
        $this->patchJson($url, ['expected_version' => $updated['version'], 'data' => ['notes' => 'Synthetic note']])
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('version', $updated['version']);
        $this->patchJson($url, ['expected_version' => $version, 'data' => ['notes' => 'Other note']])->assertConflict();

        // Read scope is required: the version comes from a read.
        $this->agent($person, [AgentApiScopes::PATIENTS_WRITE]);
        $this->patchJson($url, ['expected_version' => $updated['version'], 'data' => ['notes' => 'Other note']])->assertForbidden();
        // A viewer may read the patient but not edit it.
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $person->id, 'granted_at' => now()]);
        $this->agent($viewer, [AgentApiScopes::PATIENTS_READ, AgentApiScopes::PATIENTS_WRITE]);
        $this->patchJson($url, ['expected_version' => $updated['version'], 'data' => ['notes' => 'Other note']])->assertForbidden();
        $this->assertSame('Synthetic note', $patient->refresh()->notes);
    }

    public function test_health_logs_and_entries_update_round_trip_with_a_version_check(): void
    {
        [$person, $patient] = $this->person();
        $logId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'custom'])->assertCreated()->json('health_log.id');
        $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic other log', 'kind' => 'custom'])->assertCreated();
        $entryId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs/{$logId}/entries", ['occurred_at' => '2026-03-01T08:30:00Z', 'tags' => ['synthetic']])->assertCreated()->json('entry.id');
        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);

        $logUrl = "/api/v1/patients/{$patient->id}/health-logs/{$logId}";
        $logVersion = (string) $this->getJson($logUrl)->assertOk()->json('data.version');
        $this->patchJson($logUrl, ['expected_version' => self::STALE, 'data' => ['description' => 'Synthetic description']])->assertConflict();
        $this->patchJson($logUrl, ['expected_version' => $logVersion, 'data' => ['name' => 'Synthetic other log']])->assertUnprocessable();
        $this->patchJson($logUrl, ['expected_version' => $logVersion, 'data' => ['kind' => 'not-a-kind']])->assertUnprocessable();
        $this->travel(1)->seconds();
        $updatedLog = $this->patchJson($logUrl, ['expected_version' => $logVersion, 'data' => ['description' => 'Synthetic description', 'kind' => 'symptom']])
            ->assertOk()->assertJsonPath('outcome', 'updated')->json();
        $this->assertSame($this->getJson($logUrl)->json('data'), [...$updatedLog['data'], 'version' => $updatedLog['version']]);
        $this->assertSame(1, $updatedLog['data']['entries_count']);
        $this->patchJson($logUrl, ['expected_version' => $updatedLog['version'], 'data' => ['kind' => 'symptom']])
            ->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('version', $updatedLog['version']);
        $this->patchJson($logUrl, ['expected_version' => $logVersion, 'data' => ['kind' => 'custom']])->assertConflict();

        // A new entry is the entry's change, not the log's.
        $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs/{$logId}/entries", ['occurred_at' => '2026-03-02T08:30:00Z'])->assertCreated();
        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $this->assertSame($updatedLog['version'], $this->getJson($logUrl)->json('data.version'));

        $entryUrl = "{$logUrl}/entries/{$entryId}";
        $entryVersion = (string) $this->getJson($entryUrl)->assertOk()->json('data.version');
        $this->patchJson($entryUrl, ['expected_version' => self::STALE, 'data' => ['notes' => 'Synthetic note']])->assertConflict();
        $this->patchJson($entryUrl, ['expected_version' => $entryVersion, 'data' => ['tags' => ['a' => 'b']]])->assertUnprocessable();
        $this->patchJson($entryUrl, ['expected_version' => $entryVersion, 'data' => ['occurred_at' => null]])->assertUnprocessable();
        $this->travel(1)->seconds();
        $updatedEntry = $this->patchJson($entryUrl, ['expected_version' => $entryVersion, 'data' => ['notes' => 'Synthetic note', 'intensity' => 4, 'details' => ['meal' => 'Synthetic meal']]])
            ->assertOk()->assertJsonPath('outcome', 'updated')->assertJsonPath('health_log_id', $logId)->json();
        $this->assertSame($this->getJson($entryUrl)->json('data'), [...$updatedEntry['data'], 'version' => $updatedEntry['version']]);
        $this->assertSame(['synthetic'], $updatedEntry['data']['tags']);
        $this->assertSame(['meal' => 'Synthetic meal'], $updatedEntry['data']['details']);
        $this->patchJson($entryUrl, ['expected_version' => $updatedEntry['version'], 'data' => ['intensity' => 4]])
            ->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('version', $updatedEntry['version']);
        $this->patchJson($entryUrl, ['expected_version' => $entryVersion, 'data' => ['notes' => 'Other note']])->assertConflict();
        $this->assertSame($updatedEntry['version'], $this->getJson("{$logUrl}/entries")->json('data.0.version'));
    }

    public function test_a_logs_version_is_not_moved_by_its_entries(): void
    {
        [$person, $patient] = $this->person();
        $logId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'custom'])->assertCreated()->json('health_log.id');
        $scopes = [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::CLINICAL_DELETE];
        $this->agent($person, $scopes);
        $logUrl = "/api/v1/patients/{$patient->id}/health-logs/{$logId}";
        $updated = $this->patchJson($logUrl, ['expected_version' => (string) $this->getJson($logUrl)->json('data.version'), 'data' => ['description' => 'Synthetic description']])
            ->assertOk()->assertJsonPath('outcome', 'updated')->json('version');

        // Each entry change lands in a later second, so it moves the log's updated_at (#446).
        $this->travel(5)->seconds();
        $entryId = (int) $this->postJson("{$logUrl}/entries", ['external_id' => 'synthetic-entry', 'occurred_at' => '2026-03-01T08:30:00Z'])->assertCreated()->json('data.id');
        $this->assertSame($updated, $this->getJson($logUrl)->json('data.version'));
        $this->travel(5)->seconds();
        $entryVersion = (string) $this->getJson("{$logUrl}/entries/{$entryId}")->json('data.version');
        $edited = $this->patchJson("{$logUrl}/entries/{$entryId}", ['expected_version' => $entryVersion, 'data' => ['notes' => 'Synthetic note']])->assertOk()->json('version');
        $this->assertSame($updated, $this->getJson($logUrl)->json('data.version'));
        $this->travel(5)->seconds();
        $this->deleteJson("{$logUrl}/entries/{$entryId}", ['expected_version' => $edited])->assertOk()->assertJsonPath('outcome', 'deleted');
        $this->assertSame($updated, $this->getJson($logUrl)->json('data.version'));
        $this->assertNotSame('2026-03-04 05:06:07', PhrHealthLog::query()->findOrFail($logId)->updated_at?->toDateTimeString(), 'the entries did touch the log');

        // The version read before all of that still updates the log, and its own edit moves it.
        $this->travel(5)->seconds();
        $renamed = $this->patchJson($logUrl, ['expected_version' => $updated, 'data' => ['name' => 'Synthetic renamed log']])
            ->assertOk()->assertJsonPath('outcome', 'updated')->json('version');
        $this->assertNotSame($updated, $renamed);
        $this->patchJson($logUrl, ['expected_version' => $updated, 'data' => ['kind' => 'symptom']])->assertConflict();
    }

    public function test_health_log_entries_and_logs_are_soft_deleted_as_the_browser_deletes_them(): void
    {
        [$person, $patient] = $this->person();
        $logId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'custom'])->assertCreated()->json('health_log.id');
        $entryId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs/{$logId}/entries", ['occurred_at' => '2026-03-01T08:30:00Z'])->assertCreated()->json('entry.id');
        $otherEntryId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs/{$logId}/entries", ['occurred_at' => '2026-03-02T08:30:00Z'])->assertCreated()->json('entry.id');
        $scopes = [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE];
        $this->agent($person, $scopes);

        $logUrl = "/api/v1/patients/{$patient->id}/health-logs/{$logId}";
        $entryUrl = "{$logUrl}/entries/{$entryId}";
        $entryVersion = (string) $this->getJson($entryUrl)->assertOk()->json('data.version');
        $deletedEntry = $this->assertDeletesAsTheBrowserDoes($person, "/api/phr/patients/{$patient->id}/health-logs/{$logId}/entries/{$entryId}", $entryUrl, $entryVersion, $scopes);
        $this->assertSame(['resource_type' => 'health-log-entries', 'id' => $entryId, 'patient_id' => $patient->id, 'change_type' => 'deleted', 'changed_at' => now()->toIso8601String()], $deletedEntry['data']);
        $this->assertSame($logId, $deletedEntry['health_log_id']);
        $this->assertSame([$otherEntryId], PhrHealthLogEntry::query()->pluck('id')->all());
        $this->assertNotNull(PhrHealthLogEntry::withTrashed()->findOrFail($entryId)->deleted_at);
        $this->getJson($entryUrl)->assertNotFound();
        $this->assertSame(1, $this->getJson($logUrl)->assertOk()->json('data.entries_count'));
        $this->assertTombstoneInChanges($patient, 'health-log-entries', $deletedEntry['data']);

        $this->travel(1)->minutes();
        $this->agent($person, $scopes);
        $logVersion = (string) $this->getJson($logUrl)->assertOk()->json('data.version');
        $deletedLog = $this->assertDeletesAsTheBrowserDoes($person, "/api/phr/patients/{$patient->id}/health-logs/{$logId}", $logUrl, $logVersion, $scopes);
        $this->assertSame(['resource_type' => 'health-logs', 'id' => $logId, 'patient_id' => $patient->id, 'change_type' => 'deleted', 'changed_at' => now()->toIso8601String()], $deletedLog['data']);
        $this->assertSame(0, PhrHealthLog::query()->count());
        $this->assertSame(0, PhrHealthLogEntry::query()->count());
        $this->getJson($logUrl)->assertNotFound();
        // Every entry still in the log went with it, at the same moment; the one deleted
        // earlier keeps its own time.
        $this->assertSame(now()->toDateTimeString(), PhrHealthLogEntry::withTrashed()->findOrFail($otherEntryId)->deleted_at?->toDateTimeString());
        $this->assertSame('2026-03-04 05:06:07', PhrHealthLogEntry::withTrashed()->findOrFail($entryId)->deleted_at?->toDateTimeString());
        $this->assertTombstoneInChanges($patient, 'health-logs', $deletedLog['data']);
        $this->assertTombstoneInChanges($patient, 'health-log-entries', ['resource_type' => 'health-log-entries', 'id' => $otherEntryId, 'patient_id' => $patient->id, 'change_type' => 'deleted', 'changed_at' => now()->toIso8601String()]);

        // A repeat of the entry's delete, once its log is gone too, is still settled.
        $this->agent($person, $scopes);
        $this->deleteJson($entryUrl, ['expected_version' => $entryVersion])->assertOk()->assertJsonPath('outcome', 'unchanged');
    }

    public function test_a_deleted_logs_name_can_be_used_again(): void
    {
        [$person, $patient] = $this->person();
        $first = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'custom'])->assertCreated()->json('health_log.id');
        $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'custom'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($person)->deleteJson("/api/phr/patients/{$patient->id}/health-logs/{$first}")->assertNoContent();
        $second = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'symptom'])->assertCreated()->json('health_log.id');
        $this->assertNotSame($first, $second);

        // The database still refuses two standing logs of one name (a savepoint keeps the
        // expected error from aborting the test's transaction on PostgreSQL).
        $refused = false;
        DB::beginTransaction();
        try {
            DB::table('phr_health_logs')->insert(['patient_id' => $patient->id, 'user_id' => $person->id, 'name' => 'Synthetic log', 'kind' => 'custom', 'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            $refused = true;
        }
        DB::rollBack();
        $this->assertTrue($refused);

        // ...while two deleted ones may share it.
        $this->actingAs($person)->deleteJson("/api/phr/patients/{$patient->id}/health-logs/{$second}")->assertNoContent();
        $this->assertSame(2, PhrHealthLog::onlyTrashed()->where('name', 'Synthetic log')->count());
        $this->assertSame([null, null], PhrHealthLog::onlyTrashed()->orderBy('id')->pluck('name_reserved')->all());

        // An agent creating a log under the name makes a new one, not either deleted one.
        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $created = (int) $this->postJson("/api/v1/patients/{$patient->id}/health-logs", ['external_id' => 'synthetic-log', 'name' => 'Synthetic log', 'kind' => 'custom'])
            ->assertCreated()->json('data.id');
        $this->assertNotContains($created, [$first, $second]);
    }

    public function test_respiratory_events_are_deleted_in_a_batch_as_the_browser_deletes_them(): void
    {
        [$person, $patient] = $this->person();
        foreach (['synthetic-event-1', 'synthetic-event-2', 'synthetic-event-3'] as $uuid) {
            PhrRespiratoryEvent::factory()->create(['phr_patient_id' => $patient->id, 'client_event_uuid' => $uuid]);
        }
        $body = ['uuids' => ['synthetic-event-1', 'synthetic-event-2', 'synthetic-event-unknown']];

        DB::beginTransaction();
        $this->actingAs($person)->deleteJson("/api/phr/patients/{$patient->id}/respiratory-events/batch", $body)->assertOk();
        $browser = $this->snapshot();
        DB::rollBack();

        $this->agent($person, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE]);
        $url = "/api/v1/patients/{$patient->id}/respiratory-events/batch";
        $this->deleteJson($url, ['uuids' => []])->assertUnprocessable();
        $this->deleteJson($url, $body)->assertOk()->assertExactJson([
            'resource_type' => 'respiratory-event-batch',
            'patient_id' => $patient->id,
            'deleted' => 2,
            'results' => [
                ['uuid' => 'synthetic-event-1', 'status' => 'deleted'],
                ['uuid' => 'synthetic-event-2', 'status' => 'deleted'],
                ['uuid' => 'synthetic-event-unknown', 'status' => 'not_found'],
            ],
        ]);
        $this->assertEquals($browser, $this->snapshot());
        $this->deleteJson($url, $body)->assertOk()->assertJsonPath('deleted', 0);
        $this->assertSame(['synthetic-event-3'], PhrRespiratoryEvent::query()->pluck('client_event_uuid')->all());
    }

    public function test_every_tool_answers_within_its_output_contract_over_mcp(): void
    {
        [$person, $patient] = $this->person();
        $allergy = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/allergies", ['substance' => 'Synthetic substance'])->assertCreated()->json('allergy.id');
        $logId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic log', 'kind' => 'custom'])->assertCreated()->json('health_log.id');
        $entryId = (int) $this->actingAs($person)->postJson("/api/phr/patients/{$patient->id}/health-logs/{$logId}/entries", ['occurred_at' => '2026-03-01T08:30:00Z'])->assertCreated()->json('entry.id');
        $document = (int) $this->actingAs($person)->post("/api/phr/patients/{$patient->id}/documents", [
            'document_type' => 'other',
            'file' => UploadedFile::fake()->createWithContent('synthetic note.pdf', '%PDF-1.4 synthetic mcp deletion'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('document.id');
        PhrRespiratoryEvent::factory()->create(['phr_patient_id' => $patient->id, 'client_event_uuid' => 'synthetic-event-1']);
        $this->mcpSession($person, [
            AgentApiScopes::MCP_USE, AgentApiScopes::PATIENTS_READ, AgentApiScopes::PATIENTS_WRITE,
            AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::CLINICAL_DELETE,
            AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_DELETE,
        ]);

        $patientRead = $this->tool('patients.get', ['patient_id' => $patient->id]);
        $patched = $this->tool('patients.update', ['patient_id' => $patient->id, 'expected_version' => $patientRead['data']['version'], 'data' => ['notes' => 'Synthetic note']]);
        $this->assertSame('updated', $patched['outcome']);

        $log = $this->tool('clinical.get', ['patient_id' => $patient->id, 'resource_type' => 'health-logs', 'record_id' => $logId]);
        $this->assertSame('updated', $this->tool('health_logs.update', ['patient_id' => $patient->id, 'health_log_id' => $logId, 'expected_version' => $log['data']['version'], 'data' => ['description' => 'Synthetic description']])['outcome']);
        $entry = $this->tool('health_log_entries.get', ['patient_id' => $patient->id, 'health_log_id' => $logId, 'entry_id' => $entryId]);
        $entryUpdate = $this->tool('health_log_entries.update', ['patient_id' => $patient->id, 'health_log_id' => $logId, 'entry_id' => $entryId, 'expected_version' => $entry['data']['version'], 'data' => ['details' => new \stdClass, 'notes' => 'Synthetic note']]);
        $this->assertSame('updated', $entryUpdate['outcome']);
        // An empty details object stays an object through the tool and the REST call.
        $this->assertSame('{}', str_replace(' ', '', (string) PhrHealthLogEntry::query()->findOrFail($entryId)->getRawOriginal('details')));
        $entry = $this->tool('health_log_entries.get', ['patient_id' => $patient->id, 'health_log_id' => $logId, 'entry_id' => $entryId]);
        $this->assertSame('deleted', $this->tool('health_log_entries.delete', ['patient_id' => $patient->id, 'health_log_id' => $logId, 'entry_id' => $entryId, 'expected_version' => $entry['data']['version']])['outcome']);
        $log = $this->tool('clinical.get', ['patient_id' => $patient->id, 'resource_type' => 'health-logs', 'record_id' => $logId]);
        $logDelete = ['patient_id' => $patient->id, 'health_log_id' => $logId, 'expected_version' => $log['data']['version']];
        $this->assertSame('deleted', $this->tool('health_logs.delete', $logDelete)['outcome']);
        $this->assertSame('unchanged', $this->tool('health_logs.delete', $logDelete)['outcome']);

        $record = $this->tool('clinical.get', ['patient_id' => $patient->id, 'resource_type' => 'allergies', 'record_id' => $allergy]);
        $stale = $this->callTool('clinical.delete', ['patient_id' => $patient->id, 'resource_type' => 'allergies', 'record_id' => $allergy, 'expected_version' => self::STALE]);
        $this->assertTrue($stale['isError'] ?? false);
        $arguments = ['patient_id' => $patient->id, 'resource_type' => 'allergies', 'record_id' => $allergy, 'expected_version' => $record['data']['version']];
        $this->assertSame('deleted', $this->tool('clinical.delete', $arguments)['outcome']);
        $this->assertSame('unchanged', $this->tool('clinical.delete', $arguments)['outcome']);

        $documentRead = $this->tool('documents.get', ['patient_id' => $patient->id, 'document_id' => $document]);
        $this->assertSame('deleted', $this->tool('documents.delete', ['patient_id' => $patient->id, 'document_id' => $document, 'expected_version' => $documentRead['data']['version']])['outcome']);
        $this->assertSame(1, $this->tool('respiratory_events.delete', ['patient_id' => $patient->id, 'uuids' => ['synthetic-event-1']])['deleted']);
    }

    /**
     * Deletes the record through the browser inside a savepoint, rolls that back,
     * deletes it through the agent API, and asserts every PHR table ends the same:
     * the agent's delete has exactly the browser's side effects. Then the version
     * precondition and the repeat.
     *
     * @param  list<string>  $scopes
     * @return array<string, mixed> the agent's delete response
     */
    private function assertDeletesAsTheBrowserDoes(User $person, string $browserUrl, string $agentUrl, string $version, array $scopes, int $repeatAnswers = 200): array
    {
        DB::beginTransaction();
        $this->actingAs($person)->deleteJson($browserUrl)->assertNoContent();
        $browser = $this->snapshot();
        DB::rollBack();
        $before = $this->snapshot();

        $this->agent($person, $scopes);
        $this->deleteJson($agentUrl, [])->assertUnprocessable();
        $this->deleteJson($agentUrl, ['expected_version' => self::STALE])->assertConflict();
        $this->assertEquals($before, $this->snapshot(), 'A stale version changed nothing.');

        $deleted = $this->deleteJson($agentUrl, ['expected_version' => $version])
            ->assertOk()
            ->assertJsonPath('outcome', 'deleted')
            ->json();
        $this->assertEquals($browser, $this->snapshot(), 'The agent delete left the patient as the browser delete does.');

        $repeat = $this->deleteJson($agentUrl, ['expected_version' => $version])->assertStatus($repeatAnswers);
        if ($repeatAnswers === 200) {
            // Settled whatever version arrives: the delete asked for has happened.
            $repeat->assertJsonPath('outcome', 'unchanged')->assertJsonPath('data', $deleted['data']);
            $this->deleteJson($agentUrl, ['expected_version' => self::STALE])->assertOk()->assertJsonPath('outcome', 'unchanged');
        }
        $this->assertEquals($browser, $this->snapshot());

        return $deleted;
    }

    /** @param  array<string, mixed>  $tombstone */
    private function assertTombstoneInChanges(PhrPatient $patient, string $type, array $tombstone): void
    {
        if (! in_array($type, AgentRecordSearchCatalog::ids(), true)) {
            // changes.list does not follow this type (medication transitions) at all.
            // Its rows are soft-deleted all the same, so it can join the feed later.
            return;
        }
        $this->agent(User::query()->findOrFail($patient->owner_user_id), [AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $changes = $this->getJson("/api/v1/patients/{$patient->id}/changes?".http_build_query(['resource_type' => [$type]]))->assertOk()->json('data');

        $this->assertContains($tombstone, $changes);
    }

    /**
     * Every row of every PHR table, in a stable order.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->filter(static fn (string $table): bool => str_starts_with($table, 'phr_'))
            ->unique()
            ->sort()
            ->values();
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->get()
                ->map(static fn (object $row): array => (array) $row)
                ->sortBy(static fn (array $row): string => (string) json_encode($row))
                ->values()
                ->all();
        }

        return $snapshot;
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function person(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        return [$owner, $patient];
    }

    /** @param  list<string>  $scopes */
    private function agent(User $user, array $scopes): void
    {
        Passport::actingAs($user, $scopes, 'api', $this->client);
    }

    private function transition(PhrPatient $patient): PhrMedicationTransition
    {
        $visit = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'visit_date' => '2026-02-09', 'encounter_class' => 'IMP',
        ]);
        $medication = PhrMedication::query()->create([
            'patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'name' => 'Synthetic home medication 10 mg oral tablet',
            'status' => 'active', 'category' => 'community', 'review_status' => PhrReviewStatus::CONFIRMED,
        ]);

        return PhrMedicationTransition::query()->create([
            'patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'office_visit_id' => $visit->id,
            'home_medication_id' => $medication->id, 'phase' => 'admission', 'action' => 'held', 'review_status' => PhrReviewStatus::CONFIRMED,
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, array<string, mixed>>
     */
    private function toolsFor(User $user, array $scopes): array
    {
        $this->mcpSession($user, $scopes);
        // Every toolset and every page: a tool absent here is withheld from this
        // connection, not merely left out of a default listing.
        $tools = [];
        $cursor = null;
        do {
            $result = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/list', 'params' => $cursor === null ? new \stdClass : ['cursor' => $cursor]], self::ALL_TOOLSETS)->json('result');
            $tools = [...$tools, ...$result['tools']];
            $cursor = $result['nextCursor'] ?? null;
        } while ($cursor !== null);

        return collect($tools)->keyBy('name')->all();
    }

    /** @param  list<string>  $scopes */
    private function mcpSession(User $user, array $scopes): void
    {
        unset($this->session);
        $this->agent($user, $scopes);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]], self::ALL_TOOLSETS);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> the tool's structured result
     */
    private function tool(string $name, array $arguments): array
    {
        $result = $this->callTool($name, $arguments);
        $this->assertFalse($result['isError'] ?? false, $name.': '.json_encode($result));

        return $result['structuredContent'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);

        return $response->json('result') ?? $this->fail('No result: '.$response->getContent());
    }

    /** @param  array<string, mixed>  $message */
    private function mcp(array $message, string $url = '/api/v1/mcp'): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson($url, $message, $headers)->assertOk();
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
