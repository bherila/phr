<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentRecordSearchCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/** Documents in records.search, timeline.list and changes.list, behind documents:read (phr#407). Synthetic data only. */
final class AgentApiDocumentSearchTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private User $actor;

    private PhrPatient $patient;

    private Client $client;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Pat Example', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->client = Client::query()->create([
            'name' => 'Synthetic Search Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }

    public function test_search_matches_a_document_title_or_summary_but_never_its_extracted_text(): void
    {
        $this->actingWith([AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $note = $this->document('Daily Progress Note', 'Nephrology follow-up', 'creatinine trend reviewed', '2026-02-11 09:30:00');
        $this->document('Plan of Care', 'Nursing plan', 'progress noted overnight', '2026-02-11 19:00:00');

        $results = $this->search(['q' => 'progress'])->assertOk()->json('data');
        $this->assertSame([['documents', $note->id]], array_map(static fn (array $row): array => [$row['resource_type'], $row['id']], $results));
        $this->assertSame('Daily Progress Note', $results[0]['summary']);
        $this->assertSame('manual_upload', $results[0]['source']);
        $this->assertNull($results[0]['source_document_id']);
        $this->assertSame('2026-02-11', substr((string) $results[0]['event_at'], 0, 10));
        $this->assertArrayNotHasKey('extracted_text', $results[0]);

        $this->assertSame([$note->id], array_column($this->search(['q' => 'NEPHROLOGY', 'resource_type' => ['documents']])->assertOk()->json('data'), 'id'));
        $this->assertSame([], $this->search(['q' => 'creatinine'])->assertOk()->json('data'), 'extracted text is not searched');
        $this->assertCount(2, $this->getJson("/api/v1/patients/{$this->patient->id}/timeline?".http_build_query(['resource_type' => ['documents']]))->assertOk()->json('data'));
    }

    public function test_documents_need_documents_read_in_search_and_the_change_feed(): void
    {
        $this->document('Daily Progress Note', null, null, '2026-02-11 09:30:00');
        $this->actingWith([AgentApiScopes::CLINICAL_READ]);

        $this->assertSame([], $this->search(['q' => 'progress'])->assertOk()->json('data'));
        $this->search(['resource_type' => ['documents']])->assertForbidden();
        $this->assertSame([], $this->changes()->assertOk()->json('data'));
        $this->changes(['resource_type' => ['documents', 'vitals']])->assertForbidden();
    }

    public function test_the_change_feed_reports_document_upserts_and_tombstones(): void
    {
        $this->actingWith([AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $kept = $this->document('Consent for treatment', null, null, '2026-02-09 14:20:00');
        $deleted = $this->document('EMS run report', null, null, '2026-02-09 13:00:00');
        $deleted->delete();

        $changes = collect($this->changes(['resource_type' => ['documents']])->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('upsert', $changes[$kept->id]['change_type']);
        $this->assertSame('documents', $changes[$kept->id]['resource_type']);
        $this->assertSame('Consent for treatment', $changes[$kept->id]['data']['summary']);
        $this->assertSame('deleted', $changes[$deleted->id]['change_type']);
        $this->assertArrayNotHasKey('data', $changes[$deleted->id]);
        $this->assertCount(2, $this->changes()->assertOk()->json('data'), 'the default includes documents');
    }

    public function test_every_type_can_be_named_at_once_and_rest_and_mcp_agree_on_the_bound(): void
    {
        $this->actingWith([AgentApiScopes::MCP_USE, AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $this->assertCount(AgentRecordSearchCatalog::RESOURCE_TYPE_COUNT, AgentRecordSearchCatalog::ids());

        $this->search(['resource_type' => AgentRecordSearchCatalog::ids()])->assertOk();
        $this->changes(['resource_type' => AgentRecordSearchCatalog::ids()])->assertOk();

        $tools = collect($this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');
        foreach (['records.search', 'timeline.list', 'changes.list'] as $name) {
            $resourceType = $tools[$name]['inputSchema']['properties']['resource_type'];
            $this->assertSame(AgentRecordSearchCatalog::RESOURCE_TYPE_COUNT, $resourceType['maxItems'], $name);
            $this->assertSame(AgentRecordSearchCatalog::ids(), $resourceType['items']['enum'], $name);
        }

        $this->document('Daily Progress Note', null, null, '2026-02-11 09:30:00');
        $result = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => [
            'name' => 'changes.list', 'arguments' => ['patient_id' => $this->patient->id, 'resource_type' => ['documents']],
        ]])->json('result');
        $this->assertFalse($result['isError'] ?? false, json_encode($result));
        $this->assertSame('documents', $result['structuredContent']['data'][0]['resource_type']);
    }

    private function document(string $title, ?string $summary, ?string $extractedText, string $observedAt): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'title' => $title, 'document_type' => 'office_visit_note',
            'observed_at' => $observedAt, 'original_filename' => 'note.md', 'storage_disk' => PhrDocument::STORAGE_DISK,
            'mime_type' => 'text/markdown', 'byte_size' => 0, 'summary' => $summary, 'extracted_text' => $extractedText, 'source' => 'manual_upload',
        ]);
    }

    /** @param array<string, mixed> $query */
    private function search(array $query): TestResponse
    {
        return $this->getJson("/api/v1/patients/{$this->patient->id}/records/search?".http_build_query($query));
    }

    /** @param array<string, mixed> $query */
    private function changes(array $query = []): TestResponse
    {
        return $this->getJson("/api/v1/patients/{$this->patient->id}/changes?".http_build_query($query));
    }

    /** @param list<string> $scopes */
    private function actingWith(array $scopes): void
    {
        Passport::actingAs($this->actor, $scopes, 'api', $this->client);
    }

    /** @param array<string, mixed> $message */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $session = (string) $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]], $headers)->assertOk()->headers->get('Mcp-Session-Id');

        return $this->postJson('/api/v1/mcp', $message, $headers + ['Mcp-Session-Id' => $session])->assertOk();
    }
}
