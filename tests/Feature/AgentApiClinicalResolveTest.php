<?php

namespace Tests\Feature;

use App\Http\Requests\AgentApi\ResolveClinicalRecordsRequest;
use App\Models\PhrMedication;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiClinicalResolveTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
    }

    public function test_a_record_the_calling_client_upserted_resolves_with_a_byte_identical_version(): void
    {
        $actor = $this->user('resolve-owner@example.test');
        $patient = $this->patient($actor, 'Synthetic Resolve Patient');
        $client = $this->client('Synthetic Resolve Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $upsert = $this->putJson(
            "/api/v1/patients/{$patient->id}/medications",
            $this->medicationPayload('synthetic-resolve-medication'),
        )->assertCreated()->json();

        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-resolve-medication']],
        )->assertOk()->json();

        $this->assertSame('medications', $resolved['resource_type']);
        $this->assertSame($patient->id, $resolved['patient_id']);
        $this->assertSame([], $resolved['unresolved']);
        $this->assertSame($upsert['data']['id'], $resolved['resolved']['synthetic-resolve-medication']['id']);
        $this->assertSame($upsert['version'], $resolved['resolved']['synthetic-resolve-medication']['version']);
        $this->assertSame(
            'pending_review',
            $resolved['resolved']['synthetic-resolve-medication']['review_status'],
        );
        $this->assertIsString($resolved['resolved']['synthetic-resolve-medication']['updated_at']);
    }

    public function test_an_unknown_external_id_is_unresolved(): void
    {
        $actor = $this->user('resolve-unknown@example.test');
        $patient = $this->patient($actor, 'Synthetic Unknown Patient');
        $client = $this->client('Synthetic Unknown Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);

        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['never-written']],
        )->assertOk()->json();

        $this->assertSame([], $resolved['resolved']);
        $this->assertSame(['never-written'], $resolved['unresolved']);
    }

    public function test_a_record_written_by_a_different_oauth_client_is_unresolved(): void
    {
        $actor = $this->user('resolve-cross-client@example.test');
        $patient = $this->patient($actor, 'Synthetic Cross Client Patient');
        $firstClient = $this->client('Synthetic Cross Client A');
        $secondClient = $this->client('Synthetic Cross Client B');

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $firstClient);
        $this->putJson(
            "/api/v1/patients/{$patient->id}/medications",
            $this->medicationPayload('synthetic-cross-client-medication'),
        )->assertCreated();

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $secondClient);
        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-cross-client-medication']],
        )->assertOk()->json();

        $this->assertSame([], $resolved['resolved']);
        $this->assertSame(['synthetic-cross-client-medication'], $resolved['unresolved']);
    }

    public function test_a_browser_created_record_with_a_matching_external_id_is_unresolved(): void
    {
        $actor = $this->user('resolve-browser@example.test');
        $patient = $this->patient($actor, 'Synthetic Browser Patient');
        $client = $this->client('Synthetic Browser Client');

        // A record entered by a person in the browser: no agent client ever
        // wrote it, so its import_source does not carry the client namespace.
        PhrMedication::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'name' => 'Browser medication',
            'external_id' => 'synthetic-browser-medication',
            'review_status' => 'confirmed',
        ]);

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-browser-medication']],
        )->assertOk()->json();

        $this->assertSame([], $resolved['resolved']);
        $this->assertSame(['synthetic-browser-medication'], $resolved['unresolved']);
    }

    public function test_a_record_belonging_to_a_different_patient_is_unresolved(): void
    {
        $actor = $this->user('resolve-cross-patient@example.test');
        $patient = $this->patient($actor, 'Synthetic Cross Patient A');
        $otherPatient = $this->patient($actor, 'Synthetic Cross Patient B');
        $client = $this->client('Synthetic Cross Patient Client');

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->putJson(
            "/api/v1/patients/{$otherPatient->id}/medications",
            $this->medicationPayload('synthetic-cross-patient-medication'),
        )->assertCreated();

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-cross-patient-medication']],
        )->assertOk()->json();

        $this->assertSame([], $resolved['resolved']);
        $this->assertSame(['synthetic-cross-patient-medication'], $resolved['unresolved']);
    }

    public function test_an_empty_result_serializes_as_a_json_object_not_an_array(): void
    {
        $actor = $this->user('resolve-empty@example.test');
        $patient = $this->patient($actor, 'Synthetic Empty Patient');
        $client = $this->client('Synthetic Empty Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);

        $response = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['nothing-here']],
        )->assertOk();

        $this->assertStringContainsString('"resolved":{}', $response->getContent() ?: '');
    }

    public function test_duplicate_external_ids_collapse_to_one_entry_and_do_not_422(): void
    {
        $actor = $this->user('resolve-duplicate@example.test');
        $patient = $this->patient($actor, 'Synthetic Duplicate Patient');
        $client = $this->client('Synthetic Duplicate Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $this->putJson(
            "/api/v1/patients/{$patient->id}/medications",
            $this->medicationPayload('synthetic-duplicate-medication'),
        )->assertCreated();

        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => [
                'synthetic-duplicate-medication',
                'synthetic-duplicate-medication',
                'synthetic-duplicate-medication',
            ]],
        )->assertOk()->json();

        $this->assertCount(1, $resolved['resolved']);
        $this->assertSame([], $resolved['unresolved']);
    }

    public function test_a_mixed_batch_preserves_request_order_in_unresolved(): void
    {
        $actor = $this->user('resolve-mixed@example.test');
        $patient = $this->patient($actor, 'Synthetic Mixed Patient');
        $client = $this->client('Synthetic Mixed Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $this->putJson(
            "/api/v1/patients/{$patient->id}/medications",
            $this->medicationPayload('synthetic-mixed-known-b'),
        )->assertCreated();

        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => [
                'synthetic-mixed-unknown-a',
                'synthetic-mixed-known-b',
                'synthetic-mixed-unknown-c',
            ]],
        )->assertOk()->json();

        $this->assertSame(
            ['synthetic-mixed-unknown-a', 'synthetic-mixed-unknown-c'],
            $resolved['unresolved'],
        );
        $this->assertSame(['synthetic-mixed-known-b'], array_keys($resolved['resolved']));
    }

    public function test_validation_rejects_malformed_external_id_batches(): void
    {
        $actor = $this->user('resolve-validation@example.test');
        $patient = $this->patient($actor, 'Synthetic Validation Patient');
        $client = $this->client('Synthetic Validation Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $url = "/api/v1/patients/{$patient->id}/medications/resolve";

        $this->postJson($url, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_ids']);

        $this->postJson($url, ['external_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_ids']);

        $tooMany = array_map(static fn (int $i): string => "id-{$i}", range(1, ResolveClinicalRecordsRequest::MAX_EXTERNAL_IDS + 1));
        $this->postJson($url, ['external_ids' => $tooMany])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_ids']);

        $this->postJson($url, ['external_ids' => [123]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_ids.0']);

        $this->postJson($url, ['external_ids' => ["synthetic\x0bcontrol"]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_ids.0']);
    }

    public function test_scope_and_authentication_are_enforced(): void
    {
        $actor = $this->user('resolve-scope@example.test');
        $patient = $this->patient($actor, 'Synthetic Scope Patient');
        $client = $this->client('Synthetic Scope Client');
        $url = "/api/v1/patients/{$patient->id}/medications/resolve";

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->postJson($url, ['external_ids' => ['synthetic-id']])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson($url, ['external_ids' => ['synthetic-id']])->assertUnauthorized();
    }

    public function test_a_patient_the_user_cannot_access_is_not_found(): void
    {
        $owner = $this->user('resolve-access-owner@example.test');
        $stranger = $this->user('resolve-access-stranger@example.test');
        $patient = $this->patient($owner, 'Synthetic Access Patient');
        $client = $this->client('Synthetic Access Client');

        Passport::actingAs($stranger, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-id']],
        )->assertNotFound();
    }

    public function test_a_non_writable_resource_slug_is_not_found(): void
    {
        $actor = $this->user('resolve-non-writable@example.test');
        $patient = $this->patient($actor, 'Synthetic Non Writable Patient');
        $client = $this->client('Synthetic Non Writable Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);

        $this->postJson(
            "/api/v1/patients/{$patient->id}/health-logs/resolve",
            ['external_ids' => ['synthetic-id']],
        )->assertNotFound();
    }

    public function test_the_resolved_version_changes_after_a_browser_side_confirmation(): void
    {
        $actor = $this->user('resolve-version-change@example.test');
        $patient = $this->patient($actor, 'Synthetic Version Change Patient');
        $client = $this->client('Synthetic Version Change Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $created = $this->putJson(
            "/api/v1/patients/{$patient->id}/medications",
            $this->medicationPayload('synthetic-version-change-medication'),
        )->assertCreated()->json();

        $before = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-version-change-medication']],
        )->assertOk()->json();
        $this->assertSame($created['version'], $before['resolved']['synthetic-version-change-medication']['version']);
        $this->assertSame(
            'pending_review',
            $before['resolved']['synthetic-version-change-medication']['review_status'],
        );

        // The version HMAC covers review_status, so a browser-side confirmation
        // must change the version a subsequent resolve reports.
        $this->actingAs($actor)->patchJson(
            "/api/phr/patients/{$patient->id}/medications/{$created['data']['id']}/review",
            ['review_status' => 'confirmed'],
        )->assertOk();

        $after = $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-version-change-medication']],
        )->assertOk()->json();
        $this->assertNotSame(
            $before['resolved']['synthetic-version-change-medication']['version'],
            $after['resolved']['synthetic-version-change-medication']['version'],
        );
        $this->assertSame(
            'confirmed',
            $after['resolved']['synthetic-version-change-medication']['review_status'],
        );
    }

    public function test_the_call_is_recorded_in_the_audit_trail(): void
    {
        $actor = $this->user('resolve-audit@example.test');
        $patient = $this->patient($actor, 'Synthetic Audit Patient');
        $client = $this->client('Synthetic Audit Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);

        $this->postJson(
            "/api/v1/patients/{$patient->id}/medications/resolve",
            ['external_ids' => ['synthetic-audit-medication']],
        )->assertOk();

        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.clinical.resolve',
            'http_method' => 'POST',
            'response_status' => 200,
        ]);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function writableResourcePayloads(): array
    {
        return [
            'office-visits' => ['office-visits', ['visit_date' => '2026-01-15', 'visit_type' => 'synthetic-follow-up']],
            'procedures' => ['procedures', ['name' => 'Synthetic procedure', 'performed_on' => '2026-01-16']],
            'immunizations' => ['immunizations', ['vaccine_name' => 'Synthetic Influenza', 'administered_on' => '2026-01-10']],
            'medications' => ['medications', ['name' => 'Synthetic medication', 'status' => 'active']],
            'conditions' => ['conditions', [
                'name' => 'Synthetic condition',
                'clinical_status' => 'active',
                'verification_status' => 'confirmed',
            ]],
            'allergies' => ['allergies', [
                'substance' => 'Synthetic allergen',
                'clinical_status' => 'active',
                'verification_status' => 'confirmed',
            ]],
            'lab-results' => ['lab-results', ['analyte' => 'Synthetic analyte', 'value' => '1.23']],
            'vitals' => ['vitals', [
                'vital_name' => 'Synthetic heart rate',
                'vital_date' => '2026-01-10',
                'vital_value' => '72 beats/min',
            ]],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('writableResourcePayloads')]
    public function test_the_happy_path_resolves_across_every_writable_resource(string $resource, array $data): void
    {
        $actor = $this->user('resolve-all-'.str_replace('-', '_', $resource).'@example.test');
        $patient = $this->patient($actor, 'Synthetic All Resource Patient');
        $client = $this->client('Synthetic All Resource Client');
        $externalId = 'synthetic-resolve-all-'.$resource;
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $upsert = $this->putJson("/api/v1/patients/{$patient->id}/{$resource}", [
            'external_id' => $externalId,
            'source_document_id' => null,
            'expected_version' => null,
            'data' => $data,
        ])->assertCreated()->json();

        $resolved = $this->postJson(
            "/api/v1/patients/{$patient->id}/{$resource}/resolve",
            ['external_ids' => [$externalId]],
        )->assertOk()->json();

        $this->assertSame($resource, $resolved['resource_type']);
        $this->assertSame([], $resolved['unresolved']);
        $this->assertSame($upsert['data']['id'], $resolved['resolved'][$externalId]['id']);
        $this->assertSame($upsert['version'], $resolved['resolved'][$externalId]['version']);
    }

    /** @return array<string, mixed> */
    private function medicationPayload(string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'source_document_id' => null,
            'expected_version' => null,
            'data' => [
                'name' => 'Synthetic medication',
                'status' => 'active',
            ],
        ];
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Resolve User',
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
