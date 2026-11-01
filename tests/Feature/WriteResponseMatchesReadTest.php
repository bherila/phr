<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * A write response is a read: every endpoint that creates or changes a record
 * returns that record exactly as a later GET of it would. Database column
 * defaults are loaded, driver-normalized values and timestamps are what was
 * stored, and the serialization is the read path's own.
 *
 * Issue 253: the browser create path returned the model straight from
 * Eloquent create(), which never reads column defaults back, so a new
 * condition carried review_status null and the page reported a failure for a
 * successful write.
 *
 * test_every_write_route_is_covered_or_excused() enumerates the registered
 * routes, so a new write endpoint must join a provider below or be excused
 * with a reason.
 */
final class WriteResponseMatchesReadTest extends TestCase
{
    use ConfiguresPassportKeys;

    /**
     * Write routes whose response is not the written record, so there is no
     * later read to compare it with.
     *
     * @var array<string, string>
     */
    private const array EXCUSED_ROUTES = [
        'agent-api.v1.mcp' => 'MCP transport; its tools call the REST handlers covered here',
        'agent-api.v1.dicom-uploads.files.store' => 'returns upload-session progress, not a record',
        'agent-api.v1.dicom-uploads.open' => 'returns an upload session',
        'agent-api.v1.dicom-uploads.finalize' => 'returns an upload session',
        'agent-api.v1.dicom-uploads.cancel' => 'returns an upload session',
        'agent-api.v1.documents.download-access' => 'returns a signed URL',
        'agent-api.v1.documents.upload-access' => 'returns a signed upload session',
        'agent-api.v1.document-uploads.receive' => 'returns an upload session',
        'agent-api.v1.documents.upload-finalize' => 'returns an upload session and the document id',
        'agent-api.v1.documents.upload-cancel' => 'returns an upload session',
        'agent-api.v1.eobs.import' => 'returns an import receipt; AgentApiEobWriteTest pins that eobs.upsert and eobs.update answer exactly what eobs.get reads',
        'agent-api.v1.eobs.upsert' => 'AgentApiEobWriteTest pins that its data is exactly what eobs.get reads',
        'agent-api.v1.eobs.update' => 'AgentApiEobWriteTest pins that its data is exactly what eobs.get reads',
        'agent-api.v1.medication-fills.resolve-duplicate' => 'returns the decision (outcome, merged_into_fill_id); a merged fill is retracted and leaves every read. MedicationFillDuplicateResolutionTest pins it',
        'phr.patients.medication-fills.resolve-duplicate' => 'returns the decision and the fill as the browser reads it; MedicationFillDuplicateResolutionTest pins it',
        'agent-api.v1.exports.download-access' => 'returns a signed URL',
        'agent-api.v1.native-backups.download-access' => 'returns a signed URL',
        'agent-api.v1.imports.store' => 'returns an import job, whose records arrive later for review',
        'agent-api.v1.imports.retry' => 'returns an import job',
        'agent-api.v1.imports.results.review' => 'returns a review receipt',
        'agent-api.v1.reconciliations.apply' => 'returns a reconciliation receipt',
        'agent-api.v1.exports.store' => 'returns an export job',
        'agent-api.v1.native-backups.store' => 'returns a backup job',
        'agent-api.v1.respiratory-events.batch' => 'returns per-event batch results',
        'agent-api.v1.clinical.resolve' => 'a read: maps external IDs to record identity',
        'agent-api.v1.clinical.retract' => 'a retracted record leaves every read, so no GET can match it',
        'agent-api.v1.genai.claim' => 'GenAI queue lease, not a PHR record',
        'agent-api.v1.genai.requests.renew' => 'GenAI queue lease, not a PHR record',
        'agent-api.v1.genai.requests.complete' => 'GenAI queue completion, not a PHR record',
        'agent-api.v1.genai.requests.fail' => 'GenAI queue failure, not a PHR record',
        'phr.data-hub.deletions.retry' => 'returns a deletion job',
        'phr.data-hub.native-restores.uploads.start' => 'returns a restore session',
        'phr.data-hub.native-restores.uploads.chunk' => 'returns a restore session',
        'phr.data-hub.native-restores.preview' => 'returns a restore preview',
        'phr.data-hub.native-restores.apply' => 'returns a restore job',
        'phr.patients.access.store' => 'returns an access grant, read only as part of the patient',
        'phr.patients.eobs.review' => 'returns the reviewed EOB summary; the EOB has no browser read of its own beyond the service-date lookup',
        'phr.patients.office-visits.eobs.store' => 'returns the link, not the visit',
        'phr.patients.office-visits.imaging-studies.store' => 'returns the link, not the visit',
        'phr.patients.procedures.eobs.store' => 'returns the link, not the procedure',
        'phr.patients.dicom.uploads.open' => 'returns an upload session',
        'phr.patients.dicom.uploads.files.store' => 'returns upload-session progress',
        'phr.patients.dicom.uploads.finalize' => 'returns an upload session',
        'phr.patients.dicom.uploads.cancel' => 'returns an upload session',
        'phr.patients.dicom.series.volume-cache.store' => 'returns a volume-cache build status',
        'phr.patients.documents.process' => 'returns an import job',
        'phr.patients.ai-imports.results.accept' => 'returns an import review receipt',
        'phr.patients.ai-imports.results.reject' => 'returns an import review receipt',
        'phr.patients.ai-imports.retry' => 'returns an import job',
        'phr.patients.imports.structured.store' => 'returns an import job',
        'phr.patients.ehi.uploads.store' => 'returns an upload session',
        'phr.patients.ehi.uploads.chunks.store' => 'returns an upload session',
        'phr.patients.ehi.uploads.intake' => 'returns an intake job',
        'phr.patients.ehi.packages.binding.store' => 'returns a package binding',
        'phr.patients.ehi.packages.preview' => 'returns a preview run',
        'phr.patients.ehi.proposals.review' => 'returns a proposal review receipt',
        'phr.patients.exports.store' => 'returns an export job',
        'phr.patients.native-backups.store' => 'returns a backup job',
        'phr.genai.results.accept' => 'returns an import review receipt',
        'phr.respiratory-events.batch.store' => 'returns per-event batch results',
        'phr.respiratory-events.flag-batch.store' => 'returns per-event batch results',
        'phr.sinus.settings.update' => 'returns a settings reconciliation, not a patient record',
        'phr.sinus.enrollments.batch.store' => 'returns per-enrollment batch results',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
        // Sub-second precision is what an unrefreshed model carries and the
        // database does not store, so freezing on a fractional second makes a
        // response built from memory differ from one built from a read.
        $this->travelTo(Carbon::parse('2026-03-04 05:06:07.654321'));
    }

    /**
     * Browser endpoints, with the smallest create payload each accepts so
     * every omitted column falls to its database default.
     *
     * @return array<string, array{string, string, string, array<string, mixed>, array<string, mixed>}>
     */
    public static function browserWrites(): array
    {
        return [
            'patients' => ['phr.patients.store', 'phr.patients.update', 'patient', ['display_name' => 'Synthetic Patient'], ['display_name' => 'Synthetic Patient Renamed']],
            'lab results' => ['phr.patients.lab-results.store', 'phr.patients.lab-results.update', 'lab_result', ['analyte' => 'Synthetic analyte'], ['value' => '14.1']],
            'vitals' => ['phr.patients.vitals.store', 'phr.patients.vitals.update', 'vital', ['vital_name' => 'Heart Rate'], ['vital_value' => '72']],
            'office visits' => ['phr.patients.office-visits.store', 'phr.patients.office-visits.update', 'office_visit', ['visit_date' => '2026-01-15'], ['assessment' => 'Synthetic assessment']],
            'medications' => ['phr.patients.medications.store', 'phr.patients.medications.update', 'medication', ['name' => 'Synthetic medication'], ['dose' => '10']],
            'medication fills' => ['phr.patients.medication-fills.store', 'phr.patients.medication-fills.update', 'medication_fill', ['filled_on' => '2026-01-15', 'quantity' => '30', 'drug_as_printed' => 'Synthetic drug 10 mg tab'], ['quantity_unit' => 'tablet']],
            'conditions' => ['phr.patients.conditions.store', 'phr.patients.conditions.update', 'condition', ['name' => 'Synthetic condition'], ['notes' => 'Synthetic note']],
            'procedures' => ['phr.patients.procedures.store', 'phr.patients.procedures.update', 'procedure', ['name' => 'Synthetic procedure'], ['outcome' => 'Synthetic outcome']],
            'immunizations' => ['phr.patients.immunizations.store', 'phr.patients.immunizations.update', 'immunization', ['vaccine_name' => 'Synthetic vaccine'], ['site' => 'Left deltoid']],
            'allergies' => ['phr.patients.allergies.store', 'phr.patients.allergies.update', 'allergy', ['substance' => 'Synthetic substance'], ['reaction' => 'Synthetic hives']],
            'documents' => ['phr.patients.documents.store', 'phr.patients.documents.update', 'document', ['document_type' => 'other'], ['summary' => 'Synthetic summary']],
            'health logs' => ['phr.patients.health-logs.store', 'phr.patients.health-logs.update', 'health_log', ['name' => 'Synthetic log', 'kind' => 'custom'], ['description' => 'Synthetic description']],
            'health log entries' => ['phr.patients.health-logs.entries.store', 'phr.patients.health-logs.entries.update', 'entry', ['occurred_at' => '2026-03-01T08:30:00Z'], ['notes' => 'Synthetic entry note']],
        ];
    }

    /**
     * @param  array<string, mixed>  $create
     * @param  array<string, mixed>  $update
     */
    #[DataProvider('browserWrites')]
    public function test_browser_write_responses_match_a_later_read(
        string $storeRoute,
        string $updateRoute,
        string $key,
        array $create,
        array $update,
    ): void {
        $this->actingAs($this->createUser());
        $parameters = $this->browserParameters($storeRoute);
        $collection = route($storeRoute, $parameters, false);

        $created = $this->write('POST', $collection, $create)->assertCreated()->json($key);
        $id = (int) $created['id'];
        $this->assertSameAsRead($created, $this->getJson("{$collection}/{$id}")->assertOk()->json($key));

        $this->travel(1)->seconds();
        $member = route($updateRoute, [...$parameters, ...$this->memberParameter($updateRoute, $id)], false);
        $updated = $this->patchJson($member, $update)->assertOk()->json($key);
        $this->assertSameAsRead($updated, $this->getJson("{$collection}/{$id}")->assertOk()->json($key));

        $reviewRoute = str_replace('.update', '.review', $updateRoute);
        if (Route::has($reviewRoute)) {
            $this->assertSame(PhrReviewStatus::CONFIRMED, $created['review_status']);
            $this->travel(1)->seconds();
            $reviewed = $this->patchJson("{$member}/review", ['review_status' => PhrReviewStatus::REJECTED])
                ->assertOk()
                ->json($key);
            $this->assertSame(PhrReviewStatus::REJECTED, $reviewed['review_status']);
            $this->assertSameAsRead($reviewed, $this->getJson("{$collection}/{$id}")->assertOk()->json($key));
        }
    }

    /**
     * Agent API endpoints. Each read is the agent API's own GET of the record.
     *
     * @return array<string, array{string, string, array<string, mixed>, array<string, mixed>|null}>
     */
    public static function agentWrites(): array
    {
        $cases = [
            'patients' => ['agent-api.v1.patients.store', '/api/v1/patients/{id}', ['display_name' => 'Synthetic Agent Patient'], null],
            'documents' => ['agent-api.v1.documents.store', '/api/v1/patients/{patient}/documents/{id}', ['external_id' => 'synthetic-document-001', 'document_type' => 'other'], null],
            'health logs' => ['agent-api.v1.health-logs.store', '/api/v1/patients/{patient}/health-logs/{id}', ['external_id' => 'synthetic-log-001', 'name' => 'Synthetic agent log', 'kind' => 'custom'], null],
            'health log entries' => ['agent-api.v1.health-log-entries.store', '/api/v1/patients/{patient}/health-logs/{healthLog}/entries/{id}', ['external_id' => 'synthetic-entry-001', 'occurred_at' => '2026-03-01T08:30:00Z'], null],
        ];
        $clinical = [
            'office-visits' => [['visit_date' => '2026-01-15'], ['assessment' => 'Synthetic assessment']],
            'procedures' => [['name' => 'Synthetic procedure'], ['outcome' => 'Synthetic outcome']],
            'immunizations' => [['vaccine_name' => 'Synthetic vaccine'], ['site' => 'Left deltoid']],
            'medications' => [['name' => 'Synthetic medication'], ['dose' => '10']],
            'medication-fills' => [['filled_on' => '2026-01-15', 'quantity' => 30, 'source_kind' => 'manual', 'drug_as_printed' => 'Synthetic drug 10 mg tab'], ['quantity_unit' => 'tablet']],
            'conditions' => [['name' => 'Synthetic condition'], ['notes' => 'Synthetic note']],
            'allergies' => [['substance' => 'Synthetic substance'], ['reaction' => 'Synthetic hives']],
            'lab-results' => [['analyte' => 'Synthetic analyte'], ['value' => '14.1']],
            'vitals' => [['vital_name' => 'Heart Rate'], ['vital_value' => '72']],
        ];
        foreach ($clinical as $resource => [$create, $update]) {
            $cases["{$resource} upsert"] = [
                'agent-api.v1.clinical.'.str_replace('-', '_', $resource).'.upsert',
                "/api/v1/patients/{patient}/{$resource}/{id}",
                ['external_id' => "synthetic-{$resource}-001", 'source_document_id' => null, 'expected_version' => null, 'data' => $create],
                $update,
            ];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $create
     * @param  array<string, mixed>|null  $update  a clinical.update patch, for the resources that have one
     */
    #[DataProvider('agentWrites')]
    public function test_agent_write_responses_match_a_later_read(
        string $writeRoute,
        string $readPath,
        array $create,
        ?array $update,
    ): void {
        $actor = $this->createUser();
        $patient = $this->patientOwnedBy($actor);
        $healthLogId = null;
        if (str_contains($readPath, '{healthLog}')) {
            $this->actingAs($actor, 'web');
            $healthLogId = (int) $this->postJson("/api/phr/patients/{$patient->id}/health-logs", ['name' => 'Synthetic parent log', 'kind' => 'custom'])
                ->assertCreated()
                ->json('health_log.id');
        }
        Passport::actingAs($actor, [
            AgentApiScopes::PATIENTS_READ, AgentApiScopes::PATIENTS_WRITE,
            AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE,
            AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE,
        ], 'api', $this->oauthClient());

        $route = Route::getRoutes()->getByName($writeRoute);
        $this->assertInstanceOf(RoutingRoute::class, $route);
        $url = '/'.strtr($route->uri(), ['{patient}' => (string) $patient->id, '{healthLog}' => (string) $healthLogId]);
        $method = in_array('PUT', $route->methods(), true) ? 'PUT' : 'POST';

        $written = $this->write($method, $url, $create)->assertCreated()->json();
        $read = fn (int $id): array => $this->getJson(strtr($readPath, [
            '{patient}' => (string) $patient->id,
            '{healthLog}' => (string) $healthLogId,
            '{id}' => (string) $id,
        ]))->assertOk()->json('data');
        $id = (int) $written['data']['id'];
        $this->assertSameAsRead($this->agentRecord($written), $read($id));

        if ($update !== null) {
            $this->travel(1)->seconds();
            $resource = explode('/', $url)[5];
            $patched = $this->patchJson("/api/v1/patients/{$patient->id}/{$resource}/{$id}", [
                'expected_version' => $written['version'],
                'data' => $update,
            ])->assertOk()->json();
            $this->assertSameAsRead($this->agentRecord($patched), $read($id));
        }
    }

    public function test_every_write_route_is_covered_or_excused(): void
    {
        $covered = ['agent-api.v1.clinical.update' => true];
        foreach (self::browserWrites() as [$store, $update]) {
            $covered[$store] = true;
            $covered[$update] = true;
            $covered[str_replace('.update', '.review', $update)] = true;
        }
        foreach (self::agentWrites() as [$write]) {
            $covered[$write] = true;
        }

        $uncovered = [];
        $writeRoutes = [];
        foreach (Route::getRoutes() as $route) {
            $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH']);
            if ($writes === [] || ! preg_match('#^api/(phr|v1)/#', $route->uri())) {
                continue;
            }
            $name = (string) $route->getName();
            $writeRoutes[$name] = true;
            if (! isset($covered[$name]) && ! isset(self::EXCUSED_ROUTES[$name])) {
                $uncovered[] = implode('|', $writes).' '.$route->uri().' ('.($name === '' ? 'unnamed' : $name).')';
            }
        }

        $this->assertSame([], $uncovered, 'Each write route must have a case in a provider here, or a reason in EXCUSED_ROUTES.');
        $this->assertSame(
            [],
            array_keys(array_diff_key(self::EXCUSED_ROUTES, $writeRoutes)),
            'EXCUSED_ROUTES names a route that is not a registered write route.',
        );
    }

    /**
     * @param  array<string, mixed>  $written
     * @param  array<string, mixed>  $read
     */
    private function assertSameAsRead(array $written, array $read): void
    {
        $this->assertSame($read, $written, 'The write response differs from a later read of the same record.');
        if (array_key_exists('review_status', $read)) {
            $this->assertNotNull($written['review_status']);
        }
    }

    /**
     * The agent API places a record's version beside the mutated record rather
     * than inside it; its GET carries the version inside `data`.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function agentRecord(array $response): array
    {
        $this->assertFalse($response['receipt_only'] ?? false);

        return isset($response['version'])
            ? [...$response['data'], 'version' => $response['version']]
            : $response['data'];
    }

    /** @return array<string, int> */
    private function browserParameters(string $storeRoute): array
    {
        if ($storeRoute === 'phr.patients.store') {
            return [];
        }
        $patientId = (int) $this->postJson('/api/phr/patients', ['display_name' => 'Synthetic Parent Patient'])
            ->assertCreated()
            ->json('patient.id');
        if (! str_contains($storeRoute, '.health-logs.entries.')) {
            return ['patient' => $patientId];
        }
        $healthLogId = (int) $this->postJson("/api/phr/patients/{$patientId}/health-logs", ['name' => 'Synthetic parent log', 'kind' => 'custom'])
            ->assertCreated()
            ->json('health_log.id');

        return ['patient' => $patientId, 'healthLog' => $healthLogId];
    }

    /** @return array<string, int> */
    private function memberParameter(string $updateRoute, int $id): array
    {
        $route = Route::getRoutes()->getByName($updateRoute);
        $this->assertInstanceOf(RoutingRoute::class, $route);
        $names = $route->parameterNames();

        return [(string) end($names) => $id];
    }

    /** @param array<string, mixed> $payload */
    private function write(string $method, string $url, array $payload): TestResponse
    {
        if (str_ends_with($url, '/documents')) {
            return $this->post($url, [...$payload, 'file' => $this->documentFile()], ['Accept' => 'application/json']);
        }

        return $this->json($method, $url, $payload);
    }

    private function documentFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic note.pdf', '%PDF-1.4 synthetic write-read');
    }

    private function patientOwnedBy(User $owner): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Agent Parent Patient',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        return $patient;
    }

    private function oauthClient(): Client
    {
        return Client::query()->create([
            'name' => 'Synthetic Write-Read Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }
}
