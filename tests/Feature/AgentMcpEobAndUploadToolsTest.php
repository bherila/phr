<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** The signed-upload and EOB flows an agent follows over MCP, through the same REST handlers. */
final class AgentMcpEobAndUploadToolsTest extends TestCase
{
    use ConfiguresPassportKeys;
    use FakesOpenFda;
    use RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private User $actor;

    private PhrPatient $patient;

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        Storage::fake(PhrDocument::STORAGE_DISK);
        Queue::fake();
        $this->fakeOpenFda();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Mcp Eob Patient']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Mcp Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->actor, array_values(array_diff(AgentApiScopes::ids(), [AgentApiScopes::BILLING_READ])), 'api', $client);
        $this->session = (string) $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]])->headers->get('Mcp-Session-Id');
    }

    public function test_the_instructions_route_each_document_type(): void
    {
        $instructions = (string) $this->mcp(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]])->json('result.instructions');

        foreach ([
            // label, receipt, statement: signed upload, NDC, read, upsert
            'documents_upload_access_create', 'documents_upload_finalize', 'Use documents_upload only for small files',
            'ndc_lookup', 'medication_fills_list', 'medication_fills_upsert', 'one fill per receipt or statement line',
            // EOB: import, fall back to read then upsert, fills linked to lines
            'eobs_import', 'unrecognised_format', 'eobs_list', 'eobs_upsert', 'eob_line_id',
            // the client extracts; nothing is queued for AI; the Agent Inbox stays
            'PHR runs no AI', 'client_extraction_required', 'In PHR this queue is the AI Agent Inbox',
            // dedupe
            'possible_duplicate',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $instructions, $phrase);
        }
        $this->assertStringNotContainsString('are queued for AI extraction', $instructions);
    }

    public function test_the_new_tools_are_listed_with_their_input_contracts(): void
    {
        $tools = collect($this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');

        foreach (['documents.upload_access_create', 'documents.upload_finalize', 'documents.upload_cancel', 'eobs.import', 'eobs.upsert', 'eobs.update'] as $name) {
            $this->assertTrue($tools->has($name), $name);
        }
        $create = $tools['documents.upload_access_create']['inputSchema'];
        $this->assertSame(['eob', 'pharmacy_receipt', 'rx_label', 'statement', 'other'], $create['properties']['kind']['enum']);
        $this->assertContains('byte_size', $create['required']);
        $this->assertNotContains('sha256', $create['required']);
        $this->assertSame(['claim_type', 'lines'], $tools['eobs.upsert']['inputSchema']['properties']['data']['required']);
        $this->assertContains('source_document_id', $tools['eobs.upsert']['inputSchema']['required']);
        $this->assertContains('expected_version', $tools['eobs.update']['inputSchema']['required']);
        $this->assertContains('eob-line', $tools['evidence.links']['inputSchema']['properties']['resource_type']['enum']);
    }

    public function test_a_signed_upload_url_points_at_the_host_the_client_connected_to(): void
    {
        config(['agent_api.mcp_allowed_hosts' => ['phr.example.test']]);

        $response = $this->postJson('https://phr.example.test/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => [
                'name' => 'documents.upload_access_create',
                'arguments' => [
                    'patient_id' => $this->patient->id, 'kind' => 'eob', 'filename' => 'eob.pdf', 'content_type' => 'application/pdf',
                    'byte_size' => strlen(self::PDF), 'sha256' => hash('sha256', self::PDF),
                ],
            ],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $this->session])->assertOk();

        $this->assertFalse($response->json('result.isError') ?? false, $response->getContent());
        $url = $response->json('result.structuredContent.data.url');
        $this->assertStringStartsWith('https://phr.example.test/api/v1/document-uploads/', $url);

        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/pdf'], self::PDF)->assertOk();
    }

    public function test_an_agent_uploads_an_eob_and_writes_it_with_its_fills_over_mcp(): void
    {
        $patientId = $this->patient->id;

        // 1. Signed upload: open, PUT, finalize.
        $open = $this->tool('documents.upload_access_create', [
            'patient_id' => $patientId, 'kind' => 'eob', 'filename' => 'eob.pdf', 'content_type' => 'application/pdf',
            'byte_size' => strlen(self::PDF), 'sha256' => hash('sha256', self::PDF),
        ]);
        $this->assertFalse($open['isError'] ?? false, json_encode($open));
        $session = $open['structuredContent']['data'];
        $this->assertSame('PUT', $session['method']);
        $this->call('PUT', $session['url'], [], [], [], ['CONTENT_TYPE' => 'application/pdf'], self::PDF)->assertOk();
        $finalized = $this->tool('documents.upload_finalize', ['patient_id' => $patientId, 'upload_id' => $session['upload_id']]);
        $this->assertFalse($finalized['isError'] ?? false, json_encode($finalized));
        $documentId = $finalized['structuredContent']['data']['document_id'];
        $this->assertSame(1, PhrDocument::query()->count());

        // 2. A server import cannot read it, and nothing was queued for AI.
        $import = $this->tool('eobs.import', ['patient_id' => $patientId, 'document_id' => $documentId]);
        $this->assertFalse($import['isError'] ?? false, json_encode($import));
        $this->assertSame('unrecognised_format', $import['structuredContent']['outcome']);
        $extraction = $this->tool('imports.create', ['patient_id' => $patientId, 'document_id' => $documentId]);
        $this->assertFalse($extraction['isError'] ?? false, json_encode($extraction));
        $this->assertSame('client_extraction_required', $extraction['structuredContent']['outcome']);
        $this->assertContains('eobs.upsert', $extraction['structuredContent']['data']['write_tools']);
        $this->assertContains('medication_fills.upsert', $extraction['structuredContent']['data']['write_tools']);
        Queue::assertNothingPushed();

        // 3. Read first, then write the claim with a fill on its pharmacy line.
        $this->assertSame([], $this->tool('eobs.list', ['patient_id' => $patientId])['structuredContent']['data']);
        $claim = [
            'patient_id' => $patientId, 'source_document_id' => $documentId,
            'data' => [
                'claim_type' => 'pharmacy', 'claim_number' => 'RX-1', 'provider_name' => 'Example Pharmacy', 'processed_date' => '2026-11-02',
                'lines' => [[
                    'procedure_code' => '5815101577', 'description' => 'atorvastatin 10 mg tablet', 'service_start' => '2026-10-28', 'patient_responsibility' => '12.50',
                    'fill' => ['ndc' => '58151-0155-77', 'quantity' => 90, 'rx_number' => '1234567'],
                ]],
            ],
        ];
        $written = $this->tool('eobs.upsert', $claim);
        $this->assertFalse($written['isError'] ?? false, json_encode($written));
        $this->assertSame('created', $written['structuredContent']['outcome']);
        $this->assertSame('pending_review', $written['structuredContent']['data']['review_status']);
        $fillId = $written['structuredContent']['fills'][0]['fill_id'];
        $lineId = $written['structuredContent']['lines'][0]['id'];
        $this->assertSame([$lineId], PhrMedicationFill::query()->findOrFail($fillId)->evidence()->pluck('eob_line_id')->all());

        $again = $this->tool('eobs.upsert', $claim);
        $this->assertSame('unchanged', $again['structuredContent']['outcome']);
        $this->assertSame(1, PhrEob::query()->count());
        $this->assertSame(1, PhrMedicationFill::query()->count());

        $line = $this->tool('eob_lines.get', ['patient_id' => $patientId, 'eob_id' => $written['structuredContent']['data']['id'], 'line_id' => $lineId]);
        $this->assertSame([$fillId], $line['structuredContent']['data']['medication_fill_ids']);
        $links = $this->tool('evidence.links', ['patient_id' => $patientId, 'resource_type' => 'eob-line', 'resource_id' => $lineId]);
        $this->assertContains(['target_type' => 'medication-fill', 'target_id' => $fillId], $links['structuredContent']['data']);

        // 4. Correct it with the version, then cancel nothing: an unused upload is cancellable.
        $updated = $this->tool('eobs.update', [
            'patient_id' => $patientId, 'eob_id' => $written['structuredContent']['data']['id'],
            'expected_version' => $written['structuredContent']['version'], 'data' => ['carrier' => 'Synthetic Plan'],
        ]);
        $this->assertSame('updated', $updated['structuredContent']['outcome']);
        $spare = $this->tool('documents.upload_access_create', ['patient_id' => $patientId, 'kind' => 'other', 'filename' => 'x.txt', 'content_type' => 'text/plain', 'byte_size' => 3]);
        $cancelled = $this->tool('documents.upload_cancel', ['patient_id' => $patientId, 'upload_id' => $spare['structuredContent']['data']['upload_id']]);
        $this->assertSame('cancelled', $cancelled['structuredContent']['data']['status']);
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

    /** @param array<string, mixed> $message */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers)->assertOk();
    }
}
