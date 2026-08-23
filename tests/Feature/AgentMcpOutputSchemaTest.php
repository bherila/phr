<?php

namespace Tests\Feature;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\AgentApi\Client\AgentApiTransport;
use App\Services\AgentApi\Client\AgentApiTransportResponse;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpToolDefinition;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * MCP output schemas are the REST response contract, enforced rather than published.
 */
final class AgentMcpOutputSchemaTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_every_tool_resolves_a_schema_from_the_rest_operation_it_mirrors(): void
    {
        $tools = $this->definitions();
        $this->assertNotEmpty($tools);

        foreach ($tools as $definition) {
            // No permissive fallback: an operation without a declared response
            // component throws here rather than advertising an open object.
            $component = AgentApiResponseSchemaCatalog::operationComponent($definition->responseOperationId());
            $schema = AgentApiResponseSchemaCatalog::schema($component);

            $this->assertSame('object', $schema['type'] ?? null, "{$definition->name} must return an object envelope.");
            $this->assertFalse(
                $schema['additionalProperties'] ?? true,
                "{$definition->name} must advertise a closed envelope, not an open object.",
            );
        }
    }

    public function test_advertised_schemas_are_standalone_and_resolve_within_themselves(): void
    {
        foreach ($this->definitions() as $definition) {
            $schema = AgentApiResponseSchemaCatalog::forOperation($definition->responseOperationId());
            $encoded = json_encode($schema, JSON_THROW_ON_ERROR);

            // Nothing may resolve against the OpenAPI document at runtime.
            $this->assertStringNotContainsString('#/components/schemas/', $encoded, $definition->name);

            preg_match_all('~"\$ref":"(#/\$defs/([A-Za-z0-9_]+))"~', $encoded, $matches);
            foreach ($matches[2] as $target) {
                $this->assertArrayHasKey(
                    $target,
                    $schema['$defs'] ?? [],
                    "{$definition->name} references [{$target}] without packaging it.",
                );
            }
        }
    }

    public function test_the_clinical_resolution_map_stays_a_typed_object(): void
    {
        // Permanent fixtures. A first sync pass returns `{}` and a record whose
        // external ID is "0" returns a numeric key; both are objects on the wire,
        // and a schema that cannot express them breaks the most common call there
        // is. Arrays are used for the stored payload elsewhere, so this is the
        // shape distinction the whole decoder exists to preserve.
        $schema = AgentApiResponseSchemaCatalog::forOperation(AgentClinicalResourceCatalog::RESOLVE_OPERATION_ID);
        $validator = new SchemaValidator;

        $record = [
            'id' => 12,
            'version' => str_repeat('a', 64),
            'review_status' => 'pending_review',
            'updated_at' => '2026-08-23 04:05:06',
            'lifecycle' => 'active',
            'retracted_at' => null,
        ];

        foreach ([(object) [], (object) ['0' => $record], (object) ['Doc-ABC' => $record]] as $resolved) {
            $errors = $validator->validateAgainstJsonSchema([
                'resource_type' => 'medications',
                'patient_id' => 4,
                'resolved' => $resolved,
                'unresolved' => ['missing-1'],
            ], $schema);

            $this->assertSame([], $errors, json_encode($errors, JSON_THROW_ON_ERROR));
        }

        // A list is drift even when empty, and an untyped entry must not pass.
        $this->assertNotSame([], $validator->validateAgainstJsonSchema([
            'resource_type' => 'medications',
            'patient_id' => 4,
            'resolved' => (object) ['Doc-ABC' => ['id' => 12]],
            'unresolved' => [],
        ], $schema));
    }

    public function test_the_closed_resolution_envelope_carries_the_lifecycle_vocabulary(): void
    {
        // These fields were declared one release before they were emitted,
        // precisely because this envelope is closed: adding them to a closed
        // schema after clients were validating against it would have made every
        // such client reject the newer response. They are now required.
        $schema = AgentApiResponseSchemaCatalog::forOperation(AgentClinicalResourceCatalog::RESOLVE_OPERATION_ID);
        $record = $schema['$defs']['ClinicalResolvedRecord'];

        $this->assertFalse($record['additionalProperties']);
        $this->assertSame(['active', 'retracted', 'deleted'], $record['properties']['lifecycle']['enum']);
        $this->assertContains('lifecycle', $record['required']);
        $this->assertContains('retracted_at', $record['required']);

        $validator = new SchemaValidator;
        $base = [
            'id' => 12,
            'version' => str_repeat('a', 64),
            'review_status' => 'pending_review',
            'updated_at' => '2026-08-23 04:05:06',
            'lifecycle' => 'active',
            'retracted_at' => null,
        ];
        $envelope = static fn (array $entry): array => [
            'resource_type' => 'medications',
            'patient_id' => 4,
            'resolved' => (object) ['Doc-ABC' => $entry],
            'unresolved' => [],
        ];

        $this->assertSame([], $validator->validateAgainstJsonSchema($envelope($base), $schema));
        $this->assertSame([], $validator->validateAgainstJsonSchema($envelope([
            ...$base,
            'lifecycle' => 'retracted',
            'retracted_at' => '2026-08-24 09:00:00',
        ]), $schema));
        // The vocabulary stays fixed.
        $this->assertNotSame([], $validator->validateAgainstJsonSchema($envelope([
            ...$base,
            'lifecycle' => 'deleted-ish',
        ]), $schema));
    }

    public function test_importer_warnings_cannot_cross_the_agent_boundary(): void
    {
        // PhrImportResult carries free-text importer warnings -- the EOB importers
        // put claim numbers and parser exception text in them -- and a failure must
        // reach an agent as a stable code, not as provider content. The review path
        // never populates them today, so the guard that matters is the closed
        // envelope: if some later path does populate them, the response is refused
        // rather than forwarded.
        $schema = AgentApiResponseSchemaCatalog::forOperation('imports.review');
        $this->assertFalse($schema['$defs']['ImportCounts']['additionalProperties']);
        $this->assertArrayNotHasKey('warnings', $schema['$defs']['ImportCounts']['properties']);

        $validator = new SchemaValidator;
        $envelope = static fn (array $counts): array => [
            'resource_type' => 'import_result',
            'patient_id' => 4,
            'job_id' => 7,
            'outcome' => 'rejected',
            'import' => $counts,
            'data' => ['id' => 9, 'status' => 'skipped'],
        ];
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 1, 'documents' => 0];

        $this->assertSame([], $validator->validateAgainstJsonSchema($envelope($counts), $schema));
        $this->assertNotSame([], $validator->validateAgainstJsonSchema(
            $envelope([...$counts, 'warnings' => ['claim 12345: synthetic parser failure']]),
            $schema,
        ));
    }

    public function test_tools_list_advertises_the_strict_envelope(): void
    {
        $this->actingAsAgent('mcp-output-schema@example.test');
        $session = $this->initializeSession();

        $tools = $this->mcpPost([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
        ], $session)->assertOk()->json('result.tools');

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool['name']] = $tool;
        }

        $resolve = $byName[AgentClinicalResourceCatalog::mcpResolveToolId('medications')]['outputSchema'] ?? null;
        $this->assertIsArray($resolve);
        $this->assertFalse($resolve['additionalProperties'] ?? true);
        $this->assertSame(
            '#/$defs/ClinicalResolvedRecord',
            $resolve['properties']['resolved']['additionalProperties']['$ref'] ?? null,
        );
        $this->assertArrayHasKey('ClinicalResolvedRecord', $resolve['$defs'] ?? []);

        foreach ($byName as $name => $tool) {
            $this->assertNotSame(
                true,
                $tool['outputSchema']['additionalProperties'] ?? null,
                "{$name} still advertises an open output schema.",
            );
        }
    }

    public function test_a_result_that_breaks_its_output_contract_is_refused_without_leaking_it(): void
    {
        $actor = $this->actingAsAgent('mcp-output-drift@example.test');
        $this->patient($actor, 'Synthetic Output Drift Patient');

        // Passes the DAO's envelope check but not the Patient component, which is
        // closed. This is the drift an output schema exists to catch.
        $this->app->bind(AgentApiTransport::class, fn (): AgentApiTransport => new class implements AgentApiTransport
        {
            public function send(string $method, string $path, array $query = [], ?array $json = null, mixed $multipart = null): AgentApiTransportResponse
            {
                return new AgentApiTransportResponse(200, [
                    'data' => [['id' => 1, 'undeclared_column' => 'synthetic-leak']],
                    'pagination' => ['limit' => 25, 'has_more' => false, 'next_cursor' => null],
                ]);
            }
        });

        $session = $this->initializeSession();
        $response = $this->mcpPost([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'patients.list', 'arguments' => []],
        ], $session)->assertOk();

        $body = (string) $response->getContent();
        $this->assertSame(-32603, $response->json('error.code'));
        $this->assertNull($response->json('result'));
        // Neither the offending value nor the validator's pointers may escape.
        $this->assertStringNotContainsString('synthetic-leak', $body);
        $this->assertStringNotContainsString('undeclared_column', $body);
    }

    /** @return list<AgentMcpToolDefinition> */
    private function definitions(): array
    {
        return app(AgentMcpToolCatalog::class)->definitions(
            app(AgentMcpReadTools::class),
            app(AgentMcpWriteTools::class),
        );
    }

    private function actingAsAgent(string $email): User
    {
        $actor = User::factory()->create([
            'name' => 'Synthetic MCP User',
            'email' => $email,
            'user_role' => 'user',
        ]);
        $client = Client::factory()->create(['name' => 'Synthetic Output Schema Client']);
        Passport::actingAs($actor, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::PATIENTS_READ,
            AgentApiScopes::CLINICAL_READ,
        ], 'api', $client);

        return $actor;
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

    private function initializeSession(): string
    {
        $response = $this->mcpPost([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'Synthetic MCP Test Client', 'version' => '1.0.0'],
            ],
        ])->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return TestResponse<Response>
     */
    private function mcpPost(array $message, ?string $session = null): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers);
    }
}
