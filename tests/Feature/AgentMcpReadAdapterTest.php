<?php

namespace Tests\Feature;

use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Models\AgentApiAudit;
use App\Models\PhrDocument;
use App\Models\PhrHealthLog;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrRespiratoryEvent;
use App\Models\User;
use App\Services\AgentApi\Client\InternalAgentApiTransport;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentRecordSearchCatalog;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentMcpReadAdapterTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
    }

    public function test_discovery_and_openapi_activate_mcp_atomically(): void
    {
        $this->assertContains(AgentApiScopes::MCP_USE, AgentApiScopes::ids());
        $this->assertArrayNotHasKey(AgentApiScopes::MCP_USE, AgentApiScopes::reservedDescriptions());
        $this->assertContains(AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::ids());
        $this->assertArrayNotHasKey(AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::reservedDescriptions());
        $this->assertContains(AgentApiScopes::DOCUMENTS_WRITE, AgentApiScopes::ids());
        $this->assertArrayNotHasKey(AgentApiScopes::DOCUMENTS_WRITE, AgentApiScopes::reservedDescriptions());
        $this->assertContains(AgentApiScopes::IMPORTS_READ, AgentApiScopes::ids());
        $this->assertContains(AgentApiScopes::IMPORTS_WRITE, AgentApiScopes::ids());
        $this->assertArrayNotHasKey(AgentApiScopes::IMPORTS_READ, AgentApiScopes::reservedDescriptions());
        $this->assertArrayNotHasKey(AgentApiScopes::IMPORTS_WRITE, AgentApiScopes::reservedDescriptions());

        $this->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/v1'))
            ->assertJsonPath('scopes_supported', AgentApiScopes::ids());
        $capabilities = $this->getJson('/api/v1/capabilities')
            ->assertOk()
            ->assertJsonPath('mcp_url', url('/api/v1/mcp'))
            ->assertJsonPath('limits.mcp_max_body_bytes', 262_144)
            ->assertJsonPath('limits.mcp_session_ttl_seconds', 1800)
            ->json();
        $this->assertSame(AgentApiScopes::MCP_USE, $capabilities['operations']['mcp.connect']['scope'] ?? null);

        $contract = json_decode(
            file_get_contents(public_path('openapi/phr-agent-v1.json')) ?: '',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame(
            'mcp:use',
            $contract['paths']['/mcp']['post']['security'][0]['oauth2'][0] ?? null,
        );
        $this->assertArrayHasKey(
            AgentApiScopes::MCP_USE,
            $contract['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] ?? [],
        );
    }

    public function test_mcp_requires_its_own_scope_and_accepts_unauthenticated_preflight(): void
    {
        $user = $this->user('mcp-scope@example.test');
        Passport::actingAs($user, [AgentApiScopes::PATIENTS_READ]);

        $this->mcpPost($this->initializeMessage())->assertForbidden();

        $this->options('/api/v1/mcp', [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ])->assertNoContent();
    }

    public function test_internal_rest_transport_forwards_bearer_auth_but_never_browser_cookies(): void
    {
        Route::get('/api/v1/_synthetic-mcp-transport-check', static fn (Request $request) => response()->json([
            'authorization' => $request->header('Authorization'),
            'cookies' => $request->cookies->all(),
        ]));
        $outer = Request::create(
            '/api/v1/mcp',
            'POST',
            cookies: ['phr_session' => 'synthetic-cookie-secret'],
            server: ['HTTP_AUTHORIZATION' => 'Bearer synthetic-mcp-token'],
        );
        $outer->headers->set('Authorization', 'Bearer synthetic-mcp-token');
        $transport = new InternalAgentApiTransport(
            app('router'),
            app(ExceptionHandler::class),
            $outer,
            app(),
        );

        $response = $transport->send('GET', '_synthetic-mcp-transport-check');

        $this->assertSame(200, $response->status);
        $this->assertSame([
            'authorization' => 'Bearer synthetic-mcp-token',
            'cookies' => [],
        ], $response->json);
    }

    public function test_mcp_lists_the_fixed_read_tool_catalog_and_reads_through_rest_authorization(): void
    {
        $actor = $this->user('mcp-reader@example.test');
        $other = $this->user('mcp-other@example.test');
        $patient = $this->patient($actor, 'Synthetic MCP Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden MCP Patient');
        PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'visit_type' => 'synthetic-mcp-visible',
            'import_source' => 'synthetic-source-a',
        ]);
        $filteredVisit = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'visit_type' => 'synthetic-mcp-filtered',
            'import_source' => 'synthetic-source-b',
        ]);
        $hiddenVisit = PhrOfficeVisit::query()->create([
            'patient_id' => $hiddenPatient->id,
            'user_id' => $other->id,
            'visit_type' => 'synthetic-mcp-hidden',
        ]);
        Passport::actingAs($actor, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::PATIENTS_READ,
            AgentApiScopes::CLINICAL_READ,
        ]);

        $initialized = $this->mcpPost($this->initializeMessage())->assertOk()->json();
        $instructions = $initialized['result']['instructions'] ?? '';
        $this->assertStringContainsString('Authorization Code plus S256 PKCE', $instructions);
        $this->assertStringContainsString('identity:read', $instructions);
        $this->assertStringContainsString('identity.get, then patients.list', $instructions);
        $this->assertStringContainsString('Never guess a patient id', $instructions);
        $this->assertStringContainsString('deterministic external_id', $instructions);

        $session = $this->initializeSession();
        $tools = $this->mcpPost([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
        ], $session)->assertOk()->json('result.tools');
        $this->assertIsArray($tools);
        $toolNames = array_column($tools, 'name');
        foreach (['capabilities.get', 'patients.list', 'records.search', 'timeline.list',
            'office_visits.list', 'procedures.get', 'eobs.list', 'documents.get', 'documents.upload',
            'imports.list', 'imports.get', 'imports.create', 'imports.review', 'imports.retry',
            'health_logs.create', 'health_log_entries.list', 'health_log_entries.get',
            'health_log_entries.append', 'respiratory_events.list', 'respiratory_events.ingest',
            'immunizations.upsert', 'medications.upsert', 'conditions.upsert', 'allergies.upsert',
            'lab_results.upsert', 'vitals.upsert'] as $name) {
            $this->assertContains($name, $toolNames);
        }
        $this->assertCount(
            26 + (count(AgentClinicalResourceCatalog::ids()) * 2) + count(AgentClinicalResourceCatalog::writableIds()),
            $toolNames,
        );
        $writeTools = [
            'documents.upload', 'imports.create', 'imports.review', 'imports.retry',
            'health_logs.create', 'health_log_entries.append', 'respiratory_events.ingest',
        ];
        foreach ($tools as $tool) {
            $this->assertSame(
                ! str_ends_with((string) $tool['name'], '.upsert') && ! in_array($tool['name'], $writeTools, true),
                $tool['annotations']['readOnlyHint'] ?? null,
            );
            $this->assertSame(
                str_ends_with((string) $tool['name'], '.upsert') || in_array($tool['name'], ['imports.review', 'imports.retry'], true),
                $tool['annotations']['destructiveHint'] ?? null,
            );
            $this->assertTrue($tool['annotations']['idempotentHint'] ?? false);
            $this->assertFalse($tool['inputSchema']['additionalProperties'] ?? true);
            $this->assertSame('object', $tool['outputSchema']['type'] ?? null);
        }
        $toolsByName = collect($tools)->keyBy('name');
        $this->assertSame(
            AgentRecordSearchCatalog::ids(),
            $toolsByName->get('records.search')['inputSchema']['properties']['resource_type']['items']['enum'] ?? null,
        );
        $this->assertSame(
            PhrDocument::DOCUMENT_TYPES,
            $toolsByName->get('documents.list')['inputSchema']['properties']['document_type']['enum'] ?? null,
        );
        $this->assertSame(
            PhrDocument::DOCUMENT_TYPES,
            $toolsByName->get('documents.upload')['inputSchema']['properties']['document_type']['enum'] ?? null,
        );
        $this->assertSame(
            DocumentUploadData::MCP_MAX_BASE64_CHARACTERS,
            $toolsByName->get('documents.upload')['inputSchema']['properties']['content_base64']['maxLength'] ?? null,
        );
        $this->assertSame(
            ['patient_id', 'external_id', 'filename', 'content_base64', 'document_type'],
            $toolsByName->get('documents.upload')['inputSchema']['required'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'archived',
            $toolsByName->get('office_visits.list')['inputSchema']['properties'] ?? [],
        );
        $this->assertArrayNotHasKey(
            'import_source',
            $toolsByName->get('health_logs.list')['inputSchema']['properties'] ?? [],
        );
        $this->assertSame(
            ['name'],
            $toolsByName->get('procedures.upsert')['inputSchema']['properties']['data']['required'] ?? null,
        );
        $this->assertSame(
            ['vaccine_name'],
            $toolsByName->get('immunizations.upsert')['inputSchema']['properties']['data']['required'] ?? null,
        );
        $this->assertSame(
            ['analyte'],
            $toolsByName->get('lab_results.upsert')['inputSchema']['properties']['data']['required'] ?? null,
        );
        $this->assertFalse(
            $toolsByName->get('office_visits.upsert')['inputSchema']['properties']['data']['additionalProperties'] ?? true,
        );
        $this->assertSame(
            PhrHealthLog::KINDS,
            $toolsByName->get('health_logs.create')['inputSchema']['properties']['kind']['enum'] ?? null,
        );
        $this->assertSame(
            PhrRespiratoryEvent::EVENT_TYPES,
            $toolsByName->get('respiratory_events.ingest')['inputSchema']['properties']['events']['items']['properties']['event_type']['enum'] ?? null,
        );

        $visible = $this->callTool($session, 3, 'office_visits.list', [
            'patient_id' => $patient->id,
            'import_source' => 'synthetic-source-b',
        ]);
        $this->assertFalse($visible['result']['isError'] ?? true);
        $this->assertCount(1, $visible['result']['structuredContent']['data'] ?? []);
        $this->assertSame($filteredVisit->id, $visible['result']['structuredContent']['data'][0]['id'] ?? null);

        $hidden = $this->callTool($session, 4, 'office_visits.get', [
            'patient_id' => $patient->id,
            'record_id' => $hiddenVisit->id,
        ]);
        $this->assertTrue($hidden['result']['isError'] ?? false);
        $this->assertSame(
            'The requested PHR resource was not found.',
            $hidden['result']['content'][0]['text'] ?? null,
        );
        $this->assertStringNotContainsString(
            'synthetic-mcp-hidden',
            json_encode($hidden, JSON_THROW_ON_ERROR),
        );

        $routeNames = AgentApiAudit::query()->pluck('route_name')->all();
        $this->assertContains('agent-api.v1.mcp', $routeNames);
        $this->assertContains('agent-api.v1.clinical.index', $routeNames);
        $this->assertContains('agent-api.v1.clinical.show', $routeNames);
    }

    public function test_underlying_rest_scope_remains_required_and_error_is_phi_safe(): void
    {
        $actor = $this->user('mcp-least-privilege@example.test');
        $patient = $this->patient($actor, 'Synthetic Scope Marker Never Persist');
        Passport::actingAs($actor, [AgentApiScopes::MCP_USE]);

        $session = $this->initializeSession();
        $result = $this->callTool($session, 2, 'patients.get', ['patient_id' => $patient->id]);

        $this->assertTrue($result['result']['isError'] ?? false);
        $this->assertSame(
            'This connection lacks the required permission.',
            $result['result']['content'][0]['text'] ?? null,
        );
        $this->assertStringNotContainsString(
            'Synthetic Scope Marker Never Persist',
            json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_mcp_clinical_upsert_uses_the_typed_rest_write_boundary(): void
    {
        $actor = $this->user('mcp-writer@example.test');
        $patient = $this->patient($actor, 'Synthetic MCP Write Patient');
        $client = Client::query()->create([
            'name' => 'Synthetic MCP Writer',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($actor, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::CLINICAL_WRITE,
        ], 'api', $client);

        $session = $this->initializeSession();
        $created = $this->callTool($session, 2, 'procedures.upsert', [
            'patient_id' => $patient->id,
            'external_id' => 'synthetic-mcp-procedure-001',
            'source_document_id' => null,
            'review_status' => 'pending_review',
            'expected_version' => null,
            'data' => [
                'name' => 'Synthetic MCP procedure',
                'performed_on' => '2026-01-18',
                'status' => 'completed',
            ],
        ]);
        $this->assertFalse($created['result']['isError'] ?? true, json_encode($created, JSON_THROW_ON_ERROR));
        $this->assertSame('created', $created['result']['structuredContent']['outcome'] ?? null);
        $this->assertSame(
            'agent-client:'.$client->id,
            $created['result']['structuredContent']['data']['import_source'] ?? null,
        );
        $this->assertDatabaseCount('phr_procedures', 1);
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.clinical.procedures.upsert',
            'response_status' => 201,
        ]);

        Passport::actingAs($actor, [AgentApiScopes::MCP_USE], 'api', $client);
        $deniedSession = $this->initializeSession();
        $denied = $this->callTool($deniedSession, 3, 'procedures.upsert', [
            'patient_id' => $patient->id,
            'external_id' => 'synthetic-mcp-denied',
            'source_document_id' => null,
            'review_status' => 'pending_review',
            'expected_version' => null,
            'data' => ['name' => 'Synthetic denied procedure'],
        ]);
        $this->assertTrue($denied['result']['isError'] ?? false);
        $this->assertSame(
            'This connection lacks the required permission.',
            $denied['result']['content'][0]['text'] ?? null,
        );
        $this->assertStringNotContainsString(
            'Synthetic denied procedure',
            json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_mcp_document_upload_uses_the_typed_multipart_rest_boundary(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        $actor = $this->user('mcp-document-writer@example.test');
        $patient = $this->patient($actor, 'Synthetic MCP Document Patient');
        $client = Client::query()->create([
            'name' => 'Synthetic MCP Document Writer',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($actor, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::DOCUMENTS_WRITE,
        ], 'api', $client);

        $session = $this->initializeSession();
        $created = $this->callTool($session, 2, 'documents.upload', [
            'patient_id' => $patient->id,
            'external_id' => 'synthetic-mcp-document-001',
            'filename' => 'synthetic.pdf',
            'content_base64' => base64_encode('%PDF-1.4 synthetic MCP document'),
            'document_type' => 'lab_report',
        ]);

        $this->assertFalse($created['result']['isError'] ?? true);
        $this->assertSame('created', $created['result']['structuredContent']['outcome'] ?? null);
        $this->assertSame(
            ['id', 'patient_id', 'processing_state'],
            array_keys($created['result']['structuredContent']['data'] ?? []),
        );
        $document = PhrDocument::query()->sole();
        $this->assertSame('agent-client:'.$client->id, $document->import_source);
        $this->assertSame([], $document->tags);
        $this->assertIsString($document->storage_path);
        Storage::disk(PhrDocument::STORAGE_DISK)->assertExists($document->storage_path);
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.documents.store',
            'response_status' => 201,
        ]);
        $this->assertStringNotContainsString(
            'synthetic MCP document',
            json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_mcp_import_tools_use_the_typed_rest_workflow(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        Storage::fake('s3');
        Queue::fake();
        $actor = $this->user('mcp-import-writer@example.test');
        $patient = $this->patient($actor, 'Synthetic MCP Import Patient');
        $path = "patients/{$patient->id}/documents/synthetic/import.pdf";
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, '%PDF-1.4 synthetic MCP import');
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'uploaded_by_user_id' => $actor->id,
            'title' => 'Synthetic MCP import',
            'document_type' => 'lab_report',
            'original_filename' => 'synthetic-import.pdf',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'byte_size' => 29,
            'file_hash' => hash('sha256', '%PDF-1.4 synthetic MCP import'),
            'source' => 'manual_upload',
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic MCP Import Writer',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($actor, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IMPORTS_READ,
            AgentApiScopes::IMPORTS_WRITE,
        ], 'api', $client);

        $session = $this->initializeSession();
        $created = $this->callTool($session, 2, 'imports.create', [
            'patient_id' => $patient->id,
            'document_id' => $document->id,
        ]);
        $this->assertFalse($created['result']['isError'] ?? true);
        $this->assertSame('created', $created['result']['structuredContent']['outcome'] ?? null);
        $job = GenAiImportJob::query()->sole();
        Queue::assertPushed(ParseImportJob::class, 1);

        $job->update(['status' => 'parsed', 'parsed_at' => now()]);
        $proposal = GenAiImportResult::query()->create([
            'job_id' => $job->id,
            'result_index' => 0,
            'result_json' => json_encode(['analyte' => 'Synthetic MCP proposal'], JSON_THROW_ON_ERROR),
            'status' => 'pending_review',
            'produced_by' => 'synthetic',
        ]);
        $shown = $this->callTool($session, 3, 'imports.get', [
            'patient_id' => $patient->id,
            'import_id' => $job->id,
        ]);
        $this->assertSame(
            'Synthetic MCP proposal',
            $shown['result']['structuredContent']['data']['results'][0]['data']['analyte'] ?? null,
        );

        $reviewed = $this->callTool($session, 4, 'imports.review', [
            'patient_id' => $patient->id,
            'import_id' => $job->id,
            'result_id' => $proposal->id,
            'action' => 'reject',
        ]);
        $this->assertFalse($reviewed['result']['isError'] ?? true, json_encode($reviewed, JSON_THROW_ON_ERROR));
        $this->assertSame('rejected', $reviewed['result']['structuredContent']['outcome'] ?? null);
        $this->assertSame(
            'Synthetic MCP proposal',
            $reviewed['result']['structuredContent']['data']['data']['analyte'] ?? null,
        );
        $this->assertSame('skipped', $proposal->refresh()->status);
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.imports.results.review',
            'response_status' => 200,
        ]);
        $this->assertStringNotContainsString(
            'Synthetic MCP proposal',
            json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_mcp_health_and_device_tools_use_the_typed_rest_workflow(): void
    {
        $actor = $this->user('mcp-health-device@example.test');
        $patient = $this->patient($actor, 'Synthetic MCP Health Device Patient');
        $client = Client::query()->create([
            'name' => 'Synthetic MCP Health Device Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($actor, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::CLINICAL_READ,
            AgentApiScopes::CLINICAL_WRITE,
        ], 'api', $client);

        $session = $this->initializeSession();
        $log = $this->callTool($session, 2, 'health_logs.create', [
            'patient_id' => $patient->id,
            'external_id' => 'synthetic-mcp-log',
            'name' => 'Synthetic MCP journal',
            'kind' => 'symptom',
        ]);
        $this->assertFalse($log['result']['isError'] ?? true, json_encode($log, JSON_THROW_ON_ERROR));
        $logId = (int) ($log['result']['structuredContent']['data']['id'] ?? 0);
        $entryResponse = $this->callToolResponse($session, 3, 'health_log_entries.append', [
            'patient_id' => $patient->id,
            'health_log_id' => $logId,
            'external_id' => 'synthetic-mcp-entry',
            'occurred_at' => '2026-08-16T13:00:00Z',
            'title' => 'Synthetic MCP observation',
            'tags' => ['synthetic'],
            'details' => ['metadata' => (object) []],
        ]);
        $entry = $entryResponse->json();
        $this->assertFalse($entry['result']['isError'] ?? true, json_encode($entry, JSON_THROW_ON_ERROR));
        $entryWirePayload = json_decode($entryResponse->getContent(), false, flags: JSON_THROW_ON_ERROR);
        $this->assertIsObject($entryWirePayload->result->structuredContent->data->details->metadata);
        $invalidDetails = $this->callTool($session, 4, 'health_log_entries.append', [
            'patient_id' => $patient->id,
            'health_log_id' => $logId,
            'external_id' => 'synthetic-mcp-array-details',
            'occurred_at' => '2026-08-16T13:01:00Z',
            'details' => [],
        ]);
        $this->assertStringContainsString(
            'Invalid parameters',
            $invalidDetails['error']['message'] ?? '',
        );
        $listed = $this->callTool($session, 5, 'health_log_entries.list', [
            'patient_id' => $patient->id,
            'health_log_id' => $logId,
        ]);
        $this->assertSame(
            'Synthetic MCP observation',
            $listed['result']['structuredContent']['data'][0]['title'] ?? null,
        );

        $ingested = $this->callTool($session, 6, 'respiratory_events.ingest', [
            'patient_id' => $patient->id,
            'events' => [[
                'client_event_uuid' => 'synthetic-mcp-respiratory',
                'event_type' => 'cough',
                'occurred_at' => '2026-08-16T13:05:00Z',
                'source' => 'desktop-mac',
            ]],
        ]);
        $this->assertFalse($ingested['result']['isError'] ?? true, json_encode($ingested, JSON_THROW_ON_ERROR));
        $this->assertSame('accepted', $ingested['result']['structuredContent']['results'][0]['status'] ?? null);
        $this->assertDatabaseCount('phr_health_log_entries', 1);
        $this->assertDatabaseCount('phr_respiratory_events', 1);
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.respiratory-events.batch',
            'response_status' => 200,
        ]);
    }

    public function test_mcp_sessions_are_isolated_by_oauth_token(): void
    {
        $first = $this->user('mcp-session-first@example.test');
        $second = $this->user('mcp-session-second@example.test');
        Passport::actingAs($first, [AgentApiScopes::MCP_USE]);
        $session = $this->initializeSession();

        Passport::actingAs($second, [AgentApiScopes::MCP_USE]);
        $response = $this->mcpPost([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
        ], $session)->assertNotFound();

        $this->assertStringNotContainsString($first->email, $response->getContent());
        $this->assertStringNotContainsString($second->email, $response->getContent());
    }

    public function test_transport_rejects_invalid_origins_protocol_versions_and_oversized_bodies(): void
    {
        $actor = $this->user('mcp-transport@example.test');
        Passport::actingAs($actor, [AgentApiScopes::MCP_USE]);

        $this->withHeader('Origin', 'https://untrusted.example.test')
            ->mcpPost($this->initializeMessage())
            ->assertForbidden();
        $this->flushHeaders();
        $this->mcpPost($this->initializeMessage(), headers: ['Mcp-Protocol-Version' => '1900-01-01'])
            ->assertBadRequest();

        config(['agent_api.mcp_max_body_bytes' => 128]);
        $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => str_repeat('x', 200), 'version' => '1'],
            ],
        ])->assertStatus(413);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $session, int $id, string $name, array $arguments): array
    {
        return $this->callToolResponse($session, $id, $name, $arguments)->json();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return TestResponse<Response>
     */
    private function callToolResponse(string $session, int $id, string $name, array $arguments): TestResponse
    {
        return $this->mcpPost([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $session)->assertOk();
    }

    private function initializeSession(): string
    {
        $response = $this->mcpPost($this->initializeMessage())->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);
        $this->assertNotSame('', $session);

        return $session;
    }

    /** @return array<string, mixed> */
    private function initializeMessage(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'Synthetic MCP Test Client', 'version' => '1.0.0'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function mcpPost(array $message, ?string $session = null, array $headers = []): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18', ...$headers];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers);
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic MCP User',
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
}
