<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** The import flow an agent follows over MCP, end to end, through the same REST handlers. */
final class AgentMcpMedicationFillToolsTest extends TestCase
{
    use ConfiguresPassportKeys;
    use FakesOpenFda;
    use RefreshDatabase;

    private User $actor;

    private PhrPatient $patient;

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
        Cache::flush();
        $this->fakeOpenFda(['00002-0800-1']);
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Mcp Patient', 'birth_date' => '1975-05-05']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Mcp Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        // billing:read would limit patients to those the person selected in the consent screen.
        Passport::actingAs($this->actor, array_values(array_diff(AgentApiScopes::ids(), [AgentApiScopes::BILLING_READ])), 'api', $client);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');
    }

    public function test_the_server_instructions_describe_the_fill_import_flow(): void
    {
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]])->json('result.instructions');

        foreach (['documents_upload', 'patients_list', 'ndc_lookup', 'ndc_search', 'medication_fills_list', 'medication_fills_upsert', 'never create a patient', 'never choose among several candidates'] as $phrase) {
            $this->assertStringContainsString($phrase, (string) $init, $phrase);
        }
    }

    public function test_every_fill_tool_is_listed_with_its_input_contract(): void
    {
        $tools = collect($this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');

        foreach (['ndc.lookup', 'ndc.search', 'medication_fills.list', 'medication_fills.get', 'medication_fills.upsert', 'medication_fills.update'] as $name) {
            $this->assertTrue($tools->has($name), $name);
        }
        $this->assertTrue($tools['ndc.lookup']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['ndc.search']['annotations']['readOnlyHint']);
        $upsert = $tools['medication_fills.upsert']['inputSchema'];
        $this->assertNotContains('external_id', $upsert['required'], 'the server derives it');
        $this->assertContains('source_document_id', $upsert['required']);
        $this->assertSame(['filled_on', 'quantity', 'source_kind'], $upsert['properties']['data']['required']);
        $this->assertArrayNotHasKey('brand_name', $upsert['properties']['data']['properties'], 'the agent never supplies drug fields');
        $this->assertArrayHasKey('ndc_candidate', $upsert['properties']['data']['properties']);
        $this->assertContains('expected_version', $tools['medication_fills.update']['inputSchema']['required']);
        $this->assertContains('medication-fills', $tools['clinical.list']['inputSchema']['properties']['resource_type']['enum']);
        $this->assertContains('medication-fills', $tools['clinical.retract']['inputSchema']['properties']['resource_type']['enum']);
        $this->assertContains('medication-fills', $tools['records.search']['inputSchema']['properties']['resource_type']['items']['enum']);
        $this->assertContains('medication-fill', $tools['evidence.links']['inputSchema']['properties']['resource_type']['enum']);
    }

    public function test_an_agent_imports_a_fill_over_mcp(): void
    {
        // 1. Identify the patient from the printed name and birth date.
        $found = $this->tool('patients.list', ['name' => 'Nobody Here']);
        $this->assertTrue($found['isError']);
        $this->assertStringContainsString('Ask the user', $found['content'][0]['text']);

        $found = $this->tool('patients.list', ['name' => 'Patient, Synthetic Mcp', 'birth_date' => '1975-05-05']);
        $this->assertFalse($found['isError'] ?? false, json_encode($found));
        $patientId = $found['structuredContent']['data'][0]['id'];
        $this->assertSame($this->patient->id, $patientId);

        // 2. Look up the NDC; read before writing.
        $lookup = $this->tool('ndc.lookup', ['ndc' => '58151015577'])['structuredContent'];
        $this->assertSame('resolved', $lookup['status']);
        $this->assertSame('Lipitor', $lookup['selected']['drug']['brand_name']);
        $this->assertSame([], $this->tool('medication_fills.list', ['patient_id' => $patientId])['structuredContent']['data']);

        // 3. Upload the source, then write the fill.
        $document = PhrDocument::query()->create([
            'patient_id' => $patientId, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id,
            'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0,
        ]);
        $arguments = [
            'patient_id' => $patientId,
            'source_document_id' => $document->id,
            'expected_version' => null,
            'data' => [
                'ndc' => '58151015577', 'filled_on' => '2026-10-28', 'quantity' => 90, 'quantity_unit' => 'tablet',
                'source_kind' => 'receipt_pdf', 'source_locator' => 'page 1', 'rx_number' => '1234567', 'pharmacy_name' => 'Example Pharmacy',
                'paid_cents' => 1000, 'retail_cents' => 18999, 'patient_name_as_printed' => 'MCP PATIENT, SYNTHETIC',
            ],
        ];
        $created = $this->tool('medication_fills.upsert', $arguments);
        $this->assertFalse($created['isError'] ?? false, json_encode($created));
        $result = $created['structuredContent'];
        $this->assertSame('created', $result['outcome']);
        $this->assertTrue($result['medication_created']);
        $this->assertSame('resolved', $result['ndc']['status']);
        $this->assertSame(PhrReviewStatus::PENDING, $result['data']['review_status']);
        $this->assertSame('MCP PATIENT, SYNTHETIC', $result['data']['patient_name_as_printed']);
        $fillId = $result['data']['id'];

        // 4. A repeat is idempotent; the list and get tools see it.
        $this->assertSame('unchanged', $this->tool('medication_fills.upsert', $arguments)['structuredContent']['outcome']);
        $listed = $this->tool('medication_fills.list', ['patient_id' => $patientId, 'filled_from' => '2026-10-01', 'ndc_status' => 'resolved', 'pharmacy' => 'example'])['structuredContent']['data'];
        $this->assertSame([$fillId], array_column($listed, 'id'));
        $got = $this->tool('medication_fills.get', ['patient_id' => $patientId, 'fill_id' => $fillId])['structuredContent'];
        $this->assertSame($result['version'], $got['data']['version']);
        $this->assertSame($fillId, $this->tool('clinical.list', ['resource_type' => 'medication-fills', 'patient_id' => $patientId])['structuredContent']['data'][0]['id']);

        // 5. Update by id and version reopens review.
        PhrMedicationFill::query()->whereKey($fillId)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $version = $this->tool('medication_fills.get', ['patient_id' => $patientId, 'fill_id' => $fillId])['structuredContent']['data']['version'];
        $updated = $this->tool('medication_fills.update', [
            'patient_id' => $patientId, 'record_id' => $fillId, 'expected_version' => $version, 'data' => ['directions' => 'Take 1 tablet by mouth daily'],
        ]);
        $this->assertFalse($updated['isError'] ?? false, json_encode($updated));
        $this->assertSame('updated', $updated['structuredContent']['outcome']);
        $this->assertSame(PhrReviewStatus::PENDING, $updated['structuredContent']['data']['review_status']);
        $stale = $this->tool('medication_fills.update', [
            'patient_id' => $patientId, 'record_id' => $fillId, 'expected_version' => $version, 'data' => ['directions' => 'x'],
        ]);
        $this->assertTrue($stale['isError']);

        // 6. Retract it as the source taking the claim back.
        $current = $updated['structuredContent']['version'];
        $retracted = $this->tool('clinical.retract', ['resource_type' => 'medication-fills', 'patient_id' => $patientId, 'record_id' => $fillId, 'expected_version' => $current]);
        $this->assertSame('retracted', $retracted['structuredContent']['lifecycle']);
    }

    public function test_ndc_search_and_an_ambiguous_ndc_over_mcp(): void
    {
        $search = $this->tool('ndc.search', ['name' => 'atorvastatin', 'strength' => '10 mg', 'package_size' => '90'])['structuredContent'];
        $this->assertSame('candidates', $search['status']);
        $this->assertNotEmpty($search['candidates'][0]['match_reasons']);

        $lookup = $this->tool('ndc.lookup', ['ndc' => '00002080001'])['structuredContent'];
        $this->assertSame('ambiguous', $lookup['status']);
        $this->assertNull($lookup['selected']);
        $this->assertCount(2, $lookup['candidates']);
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
