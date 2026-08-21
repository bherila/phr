<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrProcedure;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentClinicalWriteSchemaCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiClinicalWriteTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
    }

    public function test_upsert_is_client_namespaced_idempotent_and_optimistically_concurrent(): void
    {
        $actor = $this->user('clinical-writer@example.test');
        $patient = $this->patient($actor, 'Synthetic Write Patient');
        $client = $this->client('Synthetic Writer A');
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'uploaded_by_user_id' => $actor->id,
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
        ]);
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $payload = $this->visitPayload();
        $payload['source_document_id'] = $document->id;

        $create = $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $payload)
            ->assertCreated()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('resource_type', 'office-visits')
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.review_status', 'pending_review')
            ->assertJsonPath('data.source_document_id', $document->id)
            ->assertJsonPath('data.import_source', 'agent-client:'.$client->id)
            ->json();
        $version = $create['version'];
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $version);

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $payload)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('version', $version);
        $this->assertDatabaseCount('phr_office_visits', 1);

        $changed = $payload;
        $changed['data']['assessment'] = 'Synthetic updated assessment';
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $changed)
            ->assertConflict();

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits/{$create['data']['id']}")
            ->assertOk()
            ->assertJsonPath('data.version', $version);
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $changed['expected_version'] = $version;
        $updated = $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $changed)
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.assessment', 'Synthetic updated assessment')
            ->json();
        $this->assertNotSame($version, $updated['version']);

        // A network retry after the update is a no-op even though it carries the
        // now-stale version from the first attempt.
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $changed)
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('version', $updated['version']);

        $cleared = $changed;
        $cleared['source_document_id'] = null;
        $cleared['expected_version'] = $updated['version'];
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $cleared)
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.source_document_id', null);
    }

    public function test_the_same_external_id_cannot_cross_oauth_client_or_patient_boundaries(): void
    {
        $actor = $this->user('namespace-writer@example.test');
        $patient = $this->patient($actor, 'Synthetic Namespace Patient');
        $other = $this->user('namespace-other@example.test');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Namespace Patient');
        $firstClient = $this->client('Synthetic Namespace A');
        $secondClient = $this->client('Synthetic Namespace B');

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $firstClient);
        $this->putJson("/api/v1/patients/{$patient->id}/procedures", $this->procedurePayload())
            ->assertCreated();
        $this->putJson("/api/v1/patients/{$hiddenPatient->id}/procedures", $this->procedurePayload())
            ->assertNotFound();

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $secondClient);
        $this->putJson("/api/v1/patients/{$patient->id}/procedures", $this->procedurePayload())
            ->assertCreated();

        $this->assertDatabaseCount('phr_procedures', 2);
        $expectedSources = ['agent-client:'.$firstClient->id, 'agent-client:'.$secondClient->id];
        sort($expectedSources);
        $this->assertSame(
            $expectedSources,
            PhrProcedure::query()->pluck('import_source')->sort()->values()->all(),
        );
    }

    public function test_write_scope_patient_grant_source_document_and_validation_are_all_enforced(): void
    {
        $owner = $this->user('write-owner@example.test');
        $viewer = $this->user('write-viewer@example.test');
        $other = $this->user('write-document-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Guarded Patient');
        $otherPatient = $this->patient($other, 'Synthetic Document Patient');
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $foreignDocument = PhrDocument::query()->create([
            'patient_id' => $otherPatient->id,
            'user_id' => $other->id,
            'uploaded_by_user_id' => $other->id,
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
        ]);
        $client = $this->client('Synthetic Guard Client');

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->visitPayload())
            ->assertForbidden();

        Passport::actingAs($viewer, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->visitPayload())
            ->assertForbidden();

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $withForeignDocument = $this->visitPayload();
        $withForeignDocument['source_document_id'] = $foreignDocument->id;
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $withForeignDocument)
            ->assertNotFound();
        $invalid = $this->procedurePayload();
        unset($invalid['data']['name']);
        $invalid['data']['unknown_field'] = 'Synthetic rejected value';
        $this->putJson("/api/v1/patients/{$patient->id}/procedures", $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data', 'data.name']);
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->visitPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data', 'data.substance']);
        $this->assertDatabaseCount('phr_office_visits', 0);
        $this->assertDatabaseCount('phr_procedures', 0);
    }

    public function test_write_audits_are_metadata_only(): void
    {
        $actor = $this->user('write-audit@example.test');
        $patient = $this->patient($actor, 'Synthetic Audit Write Patient');
        $client = $this->client('Synthetic Audit Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $payload = $this->visitPayload();
        $payload['data']['assessment'] = 'synthetic-value-that-must-never-be-audited';

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $payload)
            ->assertCreated();

        $audit = AgentApiAudit::query()->sole();
        $this->assertSame('agent-api.v1.clinical.office_visits.upsert', $audit->route_name);
        $this->assertSame('PUT', $audit->http_method);
        $this->assertSame(201, $audit->response_status);
        $this->assertStringNotContainsString(
            'synthetic-value-that-must-never-be-audited',
            json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_capabilities_openapi_and_shared_write_schemas_stay_in_sync(): void
    {
        $capabilities = $this->getJson('/api/v1/capabilities')->assertOk()->json();
        $contract = json_decode(
            (string) file_get_contents(public_path('openapi/phr-agent-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            AgentClinicalResourceCatalog::writableIds(),
            $capabilities['writable_clinical_resources'],
        );
        $expectedSchemas = [
            'office-visits' => ['data_schema' => 'OfficeVisitUpsertData', 'request_schema' => 'OfficeVisitUpsertRequest'],
            'procedures' => ['data_schema' => 'ProcedureUpsertData', 'request_schema' => 'ProcedureUpsertRequest'],
            'immunizations' => ['data_schema' => 'ImmunizationUpsertData', 'request_schema' => 'ImmunizationUpsertRequest'],
            'medications' => ['data_schema' => 'MedicationUpsertData', 'request_schema' => 'MedicationUpsertRequest'],
            'conditions' => ['data_schema' => 'ConditionUpsertData', 'request_schema' => 'ConditionUpsertRequest'],
            'allergies' => ['data_schema' => 'AllergyUpsertData', 'request_schema' => 'AllergyUpsertRequest'],
            'lab-results' => ['data_schema' => 'LabResultUpsertData', 'request_schema' => 'LabResultUpsertRequest'],
            'vitals' => ['data_schema' => 'VitalUpsertData', 'request_schema' => 'VitalUpsertRequest'],
        ];
        foreach ($expectedSchemas as $resource => $contractNames) {
            $operation = AgentClinicalResourceCatalog::upsertOperationId($resource);
            $this->assertSame(
                AgentApiScopes::CLINICAL_WRITE,
                $capabilities['operations'][$operation]['scope'],
            );
            $this->assertSame(
                '#/components/schemas/'.$contractNames['request_schema'],
                $contract['paths']["/patients/{patient}/{$resource}"]['put']['requestBody']['content']['application/json']['schema']['$ref'],
            );
            $this->assertSame(
                '#/components/schemas/'.$contractNames['data_schema'],
                $contract['components']['schemas'][$contractNames['request_schema']]['allOf'][1]['properties']['data']['$ref'],
            );
            $definition = AgentClinicalResourceCatalog::definition($resource);
            $ruleClass = $definition['write_rules'] ?? null;
            $this->assertIsString($ruleClass);
            $validatedFields = array_values(array_unique(array_map(
                static fn (string $field): string => explode('.', $field, 2)[0],
                array_keys($ruleClass::rules()),
            )));
            $this->assertSame(
                $validatedFields,
                array_keys(AgentClinicalWriteSchemaCatalog::data($resource)['properties']),
            );
            $this->assertSame(
                $validatedFields,
                array_keys($contract['components']['schemas'][$contractNames['data_schema']]['properties']),
            );
        }

        $this->assertSame('identity.get', $capabilities['workflow']['patient_selection']['first']);
        $this->assertSame(AgentApiScopes::IDENTITY_READ, $capabilities['workflow']['patient_selection']['first_scope']);
        $this->assertSame('patients.list', $capabilities['workflow']['patient_selection']['enumerate']);
        $this->assertSame(AgentApiScopes::PATIENTS_READ, $capabilities['workflow']['patient_selection']['enumerate_scope']);
        $this->assertSame('patients.get', $capabilities['workflow']['patient_selection']['confirm']);
        $this->assertSame(AgentApiScopes::PATIENTS_READ, $capabilities['workflow']['patient_selection']['confirm_scope']);
        $this->assertSame('S256', $capabilities['workflow']['oauth']['authorization_code_pkce_method']);
    }

    public function test_all_core_clinical_resources_are_idempotently_writable_through_the_agent_api(): void
    {
        $actor = $this->user('all-core-writer@example.test');
        $patient = $this->patient($actor, 'Synthetic All Core Patient');
        $client = $this->client('Synthetic All Core Client');
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        $payloads = [
            'immunizations' => [
                'vaccine_name' => 'Synthetic Influenza',
                'administered_on' => '2026-01-10',
            ],
            'medications' => [
                'name' => 'Synthetic medication',
                'status' => 'active',
            ],
            'conditions' => [
                'name' => 'Synthetic condition',
                'clinical_status' => 'active',
                'verification_status' => 'confirmed',
            ],
            'allergies' => [
                'substance' => 'Synthetic allergen',
                'clinical_status' => 'active',
                'verification_status' => 'confirmed',
            ],
            'lab-results' => [
                'analyte' => 'Synthetic analyte',
                'value' => '1.23',
            ],
            'vitals' => [
                'vital_name' => 'Synthetic heart rate',
                'vital_date' => '2026-01-10',
                'vital_value' => '72 beats/min',
            ],
        ];

        foreach ($payloads as $resource => $data) {
            $payload = [
                'external_id' => 'synthetic-'.$resource.'-001',
                'source_document_id' => null,
                'review_status' => 'pending_review',
                'expected_version' => null,
                'data' => $data,
            ];
            $url = "/api/v1/patients/{$patient->id}/{$resource}";
            $created = $this->putJson($url, $payload)
                ->assertCreated()
                ->assertJsonPath('resource_type', $resource)
                ->assertJsonPath('outcome', 'created')
                ->assertJsonPath('data.review_status', 'pending_review')
                ->json();

            $this->putJson($url, $payload)
                ->assertOk()
                ->assertJsonPath('outcome', 'unchanged')
                ->assertJsonPath('version', $created['version']);
        }
    }

    /** @return array<string, mixed> */
    private function visitPayload(): array
    {
        return [
            'external_id' => 'synthetic-visit-001',
            'source_document_id' => null,
            'review_status' => 'pending_review',
            'expected_version' => null,
            'data' => [
                'visit_date' => '2026-01-15',
                'visit_type' => 'synthetic-follow-up',
                'assessment' => 'Synthetic initial assessment',
                'icd10_codes' => [['code' => 'Z00.00', 'description' => 'Synthetic examination']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function procedurePayload(): array
    {
        return [
            'external_id' => 'synthetic-procedure-001',
            'source_document_id' => null,
            'review_status' => 'confirmed',
            'expected_version' => null,
            'data' => [
                'name' => 'Synthetic procedure',
                'performed_on' => '2026-01-16',
                'status' => 'completed',
            ],
        ];
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Clinical Writer',
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
