<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\AgentApiMutationIdentity;
use App\Models\PhrHealthLog;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrRespiratoryEvent;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiHealthDeviceIngestionTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_health_log_and_entry_appends_are_client_scoped_idempotent_and_queryable(): void
    {
        $actor = $this->user('health-agent@example.test');
        $patient = $this->patient($actor, 'Synthetic Health Agent Patient');
        $client = $this->client('Synthetic Health Agent');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $logPayload = [
            'external_id' => 'synthetic-log-key',
            'name' => 'Synthetic symptom journal',
            'kind' => PhrHealthLog::KIND_SYMPTOM,
        ];
        $created = $this->postJson("/api/v1/patients/{$patient->id}/health-logs", $logPayload)
            ->assertCreated()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.entries_count', 0)
            ->json();
        $logId = (int) $created['data']['id'];

        $this->postJson("/api/v1/patients/{$patient->id}/health-logs", [
            ...$logPayload,
            'description' => null,
            'archived_at' => null,
        ])->assertOk()->assertJsonPath('outcome', 'unchanged');
        $changedLog = $logPayload;
        $changedLog['description'] = 'Synthetic conflicting description';
        $this->postJson("/api/v1/patients/{$patient->id}/health-logs", $changedLog)->assertConflict();

        $entryPayload = [
            'external_id' => 'synthetic-entry-key',
            'occurred_at' => '2026-08-16T10:30:00Z',
            'title' => 'Synthetic observation',
            'intensity' => 4,
            'tags' => ['synthetic'],
            'details' => ['source' => 'synthetic-test'],
        ];
        $entry = $this->postJson(
            "/api/v1/patients/{$patient->id}/health-logs/{$logId}/entries",
            $entryPayload,
        )->assertCreated()->assertJsonPath('outcome', 'created')->json('data');
        $this->postJson(
            "/api/v1/patients/{$patient->id}/health-logs/{$logId}/entries",
            [...$entryPayload, 'notes' => null],
        )->assertOk()->assertJsonPath('outcome', 'unchanged');
        $changedEntry = $entryPayload;
        $changedEntry['intensity'] = 5;
        $this->postJson(
            "/api/v1/patients/{$patient->id}/health-logs/{$logId}/entries",
            $changedEntry,
        )->assertConflict();

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/health-logs/{$logId}/entries?limit=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry['id'])
            ->assertJsonPath('pagination.limit', 1);
        $this->getJson("/api/v1/patients/{$patient->id}/health-logs/{$logId}/entries/{$entry['id']}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Synthetic observation');

        $this->assertDatabaseCount('phr_health_logs', 1);
        $this->assertDatabaseCount('phr_health_log_entries', 1);
        $this->assertDatabaseCount('agent_api_mutation_identities', 2);
        $this->assertFalse(AgentApiMutationIdentity::query()->pluck('external_id_hash')->contains('synthetic-log-key'));
        $this->assertSame([
            'id', 'patient_id', 'oauth_client_id', 'operation', 'external_id_hash',
            'request_hash', 'target_table', 'target_id', 'created_at', 'updated_at',
        ], Schema::getColumnListing('agent_api_mutation_identities'));
    }

    public function test_health_log_access_and_scopes_default_deny_across_patient_boundaries(): void
    {
        $owner = $this->user('health-owner@example.test');
        $viewer = $this->user('health-viewer@example.test');
        $other = $this->user('health-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Shared Health Patient');
        $hidden = $this->patient($other, 'Synthetic Hidden Health Patient');
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $log = PhrHealthLog::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'name' => 'Synthetic shared journal',
            'kind' => PhrHealthLog::KIND_CUSTOM,
        ]);
        $client = $this->client('Synthetic Scope Health Agent');

        Passport::actingAs($viewer, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/health-logs/{$log->id}/entries")->assertOk();
        $this->getJson("/api/v1/patients/{$hidden->id}/health-logs/{$log->id}/entries")->assertNotFound();
        Passport::actingAs($viewer, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/health-logs/{$log->id}/entries", [
            'external_id' => 'synthetic-denied-entry',
            'occurred_at' => '2026-08-16T12:00:00Z',
        ])->assertForbidden();
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/health-logs", [
            'external_id' => 'synthetic-missing-write-scope',
            'name' => 'Synthetic denied journal',
            'kind' => PhrHealthLog::KIND_CUSTOM,
        ])->assertForbidden();
    }

    public function test_health_log_entry_details_require_a_json_object(): void
    {
        $actor = $this->user('health-details-agent@example.test');
        $patient = $this->patient($actor, 'Synthetic Health Details Patient');
        $client = $this->client('Synthetic Health Details Agent');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $logId = (int) $this->postJson("/api/v1/patients/{$patient->id}/health-logs", [
            'external_id' => 'synthetic-details-log',
            'name' => 'Synthetic details journal',
            'kind' => PhrHealthLog::KIND_CUSTOM,
        ])->assertCreated()->json('data.id');
        $endpoint = "/api/v1/patients/{$patient->id}/health-logs/{$logId}/entries";
        $base = [
            'external_id' => 'synthetic-details-entry',
            'occurred_at' => '2026-08-16T13:00:00Z',
        ];

        $this->postJson($endpoint, [...$base, 'details' => ['synthetic-list-value']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('details');
        $this->postJson($endpoint, [...$base, 'details' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('details');

        $response = $this->call(
            'POST',
            $endpoint,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode([...$base, 'details' => (object) []], JSON_THROW_ON_ERROR),
        )->assertCreated();
        $wirePayload = json_decode($response->getContent(), false, flags: JSON_THROW_ON_ERROR);
        $this->assertIsObject($wirePayload->data->details);
    }

    public function test_agent_respiratory_ingest_reuses_device_validation_and_duplicate_semantics(): void
    {
        $actor = $this->user('respiratory-agent@example.test');
        $patient = $this->patient($actor, 'Synthetic Respiratory Agent Patient');
        $client = $this->client('Synthetic Respiratory Agent');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $events = [
            [
                'client_event_uuid' => 'synthetic-event-1',
                'event_type' => 'cough',
                'occurred_at' => '2026-08-16T08:00:00Z',
                'tz_offset_min' => -240,
                'confidence' => 0.9,
                'source' => 'desktop-mac',
                'device_id' => 'synthetic-device',
            ],
            [
                'client_event_uuid' => 'synthetic-rejected',
                'event_type' => 'unsupported',
                'occurred_at' => '2026-08-16T08:01:00Z',
            ],
        ];
        $this->postJson("/api/v1/patients/{$patient->id}/respiratory-events/batch", ['events' => $events])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'rejected');
        $this->postJson("/api/v1/patients/{$patient->id}/respiratory-events/batch", ['events' => [$events[0]]])
            ->assertOk()->assertJsonPath('results.0.status', 'duplicate');

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/respiratory-events?event_type=cough")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client_event_uuid', 'synthetic-event-1');
        PhrRespiratoryEvent::query()->sole()->update(['false_positive_at' => now()]);
        $this->getJson("/api/v1/patients/{$patient->id}/respiratory-events?include_false_positives=false")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/patients/{$patient->id}/respiratory-events?include_false_positives=true")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/patients/{$patient->id}/respiratory-events?event_type=unsupported")
            ->assertUnprocessable();
        $this->assertDatabaseCount('phr_respiratory_events', 1);
        $this->assertSame('desktop-mac', PhrRespiratoryEvent::query()->sole()->source);

        $auditJson = json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-device', $auditJson);
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.respiratory-events.batch',
            'response_status' => 200,
        ]);
    }

    public function test_health_log_retry_identities_survive_application_key_rotation(): void
    {
        $oldKey = 'base64:'.base64_encode(str_repeat('o', 32));
        $newKey = 'base64:'.base64_encode(str_repeat('n', 32));
        config(['app.key' => $oldKey, 'app.previous_keys' => []]);

        $actor = $this->user('health-rotation-agent@example.test');
        $patient = $this->patient($actor, 'Synthetic Rotation Patient');
        $client = $this->client('Synthetic Rotation Agent');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $logPayload = [
            'external_id' => 'synthetic-rotation-log',
            'name' => 'Synthetic rotation journal',
            'kind' => PhrHealthLog::KIND_CUSTOM,
        ];
        $logId = (int) $this->postJson("/api/v1/patients/{$patient->id}/health-logs", $logPayload)
            ->assertCreated()
            ->json('data.id');
        $entryPayload = [
            'external_id' => 'synthetic-rotation-entry',
            'occurred_at' => '2026-08-16T14:00:00Z',
            'title' => 'Synthetic rotation observation',
        ];
        $entryEndpoint = "/api/v1/patients/{$patient->id}/health-logs/{$logId}/entries";
        $this->postJson($entryEndpoint, $entryPayload)->assertCreated();
        $oldDigests = AgentApiMutationIdentity::query()
            ->orderBy('operation')
            ->pluck('external_id_hash', 'operation')
            ->all();

        config(['app.key' => $newKey, 'app.previous_keys' => [$oldKey]]);
        $this->postJson("/api/v1/patients/{$patient->id}/health-logs", $logPayload)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');
        $this->postJson($entryEndpoint, $entryPayload)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');
        $newDigests = AgentApiMutationIdentity::query()
            ->orderBy('operation')
            ->pluck('external_id_hash', 'operation')
            ->all();
        $this->assertNotSame($oldDigests, $newDigests);

        config(['app.previous_keys' => []]);
        $this->postJson("/api/v1/patients/{$patient->id}/health-logs", $logPayload)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');
        $this->postJson($entryEndpoint, $entryPayload)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');
        $this->postJson($entryEndpoint, [...$entryPayload, 'title' => 'Synthetic changed observation'])
            ->assertConflict();

        $this->assertDatabaseCount('phr_health_logs', 1);
        $this->assertDatabaseCount('phr_health_log_entries', 1);
        $this->assertDatabaseCount('agent_api_mutation_identities', 2);
    }

    public function test_capabilities_openapi_and_model_allow_lists_stay_in_sync(): void
    {
        $capabilities = $this->getJson('/api/v1/capabilities')->assertOk()->json();
        $contract = json_decode(
            (string) file_get_contents(public_path('openapi/phr-agent-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ([
            'health_logs.create' => AgentApiScopes::CLINICAL_WRITE,
            'health_log_entries.list' => AgentApiScopes::CLINICAL_READ,
            'health_log_entries.get' => AgentApiScopes::CLINICAL_READ,
            'health_log_entries.append' => AgentApiScopes::CLINICAL_WRITE,
            'respiratory_events.list' => AgentApiScopes::CLINICAL_READ,
            'respiratory_events.ingest' => AgentApiScopes::CLINICAL_WRITE,
        ] as $operation => $scope) {
            $this->assertSame($scope, $capabilities['operations'][$operation]['scope'] ?? null);
        }
        $this->assertSame(
            [AgentApiScopes::CLINICAL_WRITE],
            $contract['paths']['/patients/{patient}/health-logs']['post']['security'][0]['oauth2'],
        );
        $this->assertSame(
            [AgentApiScopes::CLINICAL_READ],
            $contract['paths']['/patients/{patient}/health-logs/{healthLog}/entries']['get']['security'][0]['oauth2'],
        );
        $this->assertSame(
            PhrHealthLog::KINDS,
            $contract['components']['schemas']['HealthLogCreateRequest']['properties']['kind']['enum'],
        );
        $this->assertSame(
            PhrRespiratoryEvent::EVENT_TYPES,
            $contract['components']['schemas']['RespiratoryEventType']['enum'],
        );
        $this->assertSame(
            PhrRespiratoryEvent::SOURCES,
            array_values(array_filter(
                $contract['components']['schemas']['RespiratoryEventInput']['properties']['source']['enum'],
                static fn (mixed $source): bool => $source !== null,
            )),
        );
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Health Device User',
            'email' => $email,
            'user_role' => 'user',
        ]);
    }

    private function patient(User $owner, string $displayName): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $displayName,
            'relationship' => 'self',
            'birth_date' => '2000-01-01',
            'sex_at_birth' => 'unknown',
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

    private function client(string $name): Client
    {
        return Client::query()->create([
            'name' => $name,
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }
}
