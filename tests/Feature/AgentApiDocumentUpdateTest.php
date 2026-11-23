<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/** documents.update: metadata edits guarded by the document's opaque version (phr#407). Synthetic data only. */
final class AgentApiDocumentUpdateTest extends TestCase
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
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Pat Example', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->client = Client::query()->create([
            'name' => 'Synthetic Update Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        $this->actingWith([AgentApiScopes::MCP_USE, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE]);
    }

    public function test_a_document_type_set_through_update_round_trips_and_a_stale_version_conflicts(): void
    {
        $uploaded = $this->upload()->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $uploaded['version']);
        $read = $this->getJson($this->documentUrl($uploaded['id']))->assertOk()->json('data');
        $this->assertSame($uploaded['version'], $read['version'], 'the upload hands back the version a read reports');

        $updated = $this->patchJson($this->documentUrl($uploaded['id']), [
            'expected_version' => $read['version'],
            'data' => [
                'title' => 'Daily Progress Note',
                'document_type' => 'office_visit_note',
                'observed_at' => '2026-02-11T09:30:00Z',
                'summary' => 'Nephrology follow-up; potassium 3.2 mmol/L.',
                'tags' => ['Progress-Note', 'nephrology'],
            ],
        ])->assertOk()
            ->assertJsonPath('resource_type', 'document')
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.document_type', 'office_visit_note')
            ->assertJsonPath('data.title', 'Daily Progress Note')
            ->json();
        $this->assertNotSame($read['version'], $updated['version']);
        $this->assertSame($updated['version'], $updated['data']['version']);

        $again = $this->getJson($this->documentUrl($uploaded['id']))->assertOk()->json('data');
        $this->assertSame('office_visit_note', $again['document_type']);
        $this->assertSame('Nephrology follow-up; potassium 3.2 mmol/L.', $again['summary']);
        $this->assertSame($updated['version'], $again['version']);
        $this->assertSame(['Progress-Note', 'nephrology'], $again['tags']);
        $this->assertSame('2026-02-11', substr((string) $again['observed_at'], 0, 10));

        // The version read before the edit is stale now: nothing is applied.
        $this->patchJson($this->documentUrl($uploaded['id']), [
            'expected_version' => $read['version'],
            'data' => ['document_type' => 'consent'],
        ])->assertConflict();
        $this->assertSame('office_visit_note', PhrDocument::query()->findOrFail($uploaded['id'])->document_type);

        // The same values again change nothing and keep the version.
        $this->patchJson($this->documentUrl($uploaded['id']), [
            'expected_version' => $updated['version'],
            'data' => ['document_type' => 'office_visit_note', 'title' => 'Daily Progress Note'],
        ])->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('version', $updated['version']);

        // Clearing a field.
        $this->patchJson($this->documentUrl($uploaded['id']), [
            'expected_version' => $updated['version'],
            'data' => ['summary' => null],
        ])->assertOk()->assertJsonPath('outcome', 'updated')->assertJsonPath('data.summary', null);
    }

    public function test_update_validates_its_fields_and_refuses_what_it_does_not_edit(): void
    {
        $uploaded = $this->upload()->assertCreated()->json('data');
        $url = $this->documentUrl($uploaded['id']);

        $this->patchJson($url, ['expected_version' => $uploaded['version'], 'data' => ['document_type' => 'progress_note_typo']])->assertUnprocessable();
        $this->patchJson($url, ['expected_version' => $uploaded['version'], 'data' => ['mime_type' => 'text/html']])->assertUnprocessable();
        $this->patchJson($url, ['expected_version' => $uploaded['version'], 'data' => []])->assertUnprocessable();
        $this->patchJson($url, ['data' => ['title' => 'No version']])->assertUnprocessable();
        $this->patchJson($url, ['expected_version' => str_repeat('0', 64), 'data' => ['title' => 'Fabricated version']])->assertConflict();
        $this->assertSame('Synthetic upload', PhrDocument::query()->findOrFail($uploaded['id'])->title);
    }

    public function test_update_needs_documents_read_and_write(): void
    {
        $uploaded = $this->upload()->assertCreated()->json('data');
        $body = ['expected_version' => $uploaded['version'], 'data' => ['title' => 'Changed']];

        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
        $this->patchJson($this->documentUrl($uploaded['id']), $body)->assertForbidden();
        $this->actingWith([AgentApiScopes::DOCUMENTS_READ]);
        $this->patchJson($this->documentUrl($uploaded['id']), $body)->assertForbidden();
        $this->assertSame('Synthetic upload', PhrDocument::query()->findOrFail($uploaded['id'])->title);
    }

    public function test_mcp_updates_a_document_by_its_version(): void
    {
        $uploaded = $this->upload()->assertCreated()->json('data');
        $read = $this->tool('documents.get', ['patient_id' => $this->patient->id, 'document_id' => $uploaded['id']]);
        $this->assertFalse($read['isError'] ?? false, json_encode($read));
        $version = $read['structuredContent']['data']['version'];

        $updated = $this->tool('documents.update', [
            'patient_id' => $this->patient->id, 'document_id' => $uploaded['id'], 'expected_version' => $version,
            'data' => ['document_type' => 'discharge_summary', 'observed_at' => null],
        ]);
        $this->assertFalse($updated['isError'] ?? false, json_encode($updated));
        $this->assertSame('updated', $updated['structuredContent']['outcome']);
        $this->assertSame('discharge_summary', $updated['structuredContent']['data']['document_type']);
        $this->assertNull($updated['structuredContent']['data']['observed_at']);
        $this->assertSame('pending_review', $updated['structuredContent']['data']['review_status'], 'an agent edit waits for the person');
        $pending = $this->tool('documents.list', ['patient_id' => $this->patient->id, 'review_status' => 'pending_review']);
        $this->assertFalse($pending['isError'] ?? false, json_encode($pending));
        $this->assertSame([$uploaded['id']], array_column($pending['structuredContent']['data'], 'id'));
        $this->assertSame([], $this->tool('documents.list', ['patient_id' => $this->patient->id, 'review_status' => 'confirmed'])['structuredContent']['data']);

        $stale = $this->tool('documents.update', [
            'patient_id' => $this->patient->id, 'document_id' => $uploaded['id'], 'expected_version' => $version,
            'data' => ['document_type' => 'consent'],
        ]);
        $this->assertTrue($stale['isError'] ?? false);
        $this->assertStringContainsString('current version', json_encode($stale['content'], JSON_THROW_ON_ERROR));
        $this->assertSame('discharge_summary', PhrDocument::query()->findOrFail($uploaded['id'])->document_type);

        $tools = collect($this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');
        $schema = $tools['documents.update']['inputSchema'];
        $this->assertSame(['patient_id', 'document_id', 'expected_version', 'data'], $schema['required']);
        $this->assertSame(PhrDocument::DOCUMENT_TYPES, $schema['properties']['data']['properties']['document_type']['enum']);
        $this->assertFalse($schema['properties']['data']['additionalProperties']);
    }

    private function upload(): TestResponse
    {
        return $this->post("/api/v1/patients/{$this->patient->id}/documents", [
            'external_id' => 'synthetic-update-note',
            'file' => UploadedFile::fake()->createWithContent('note.txt', 'Synthetic progress note text.'),
            'title' => 'Synthetic upload',
            'document_type' => 'other',
            'observed_at' => '2026-02-11',
            'tags' => ['synthetic'],
        ], ['Accept' => 'application/json']);
    }

    private function documentUrl(int $documentId): string
    {
        return "/api/v1/patients/{$this->patient->id}/documents/{$documentId}";
    }

    /** @param list<string> $scopes */
    private function actingWith(array $scopes): void
    {
        Passport::actingAs($this->actor, $scopes, 'api', $this->client);
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
        $session = (string) $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]], $headers)->assertOk()->headers->get('Mcp-Session-Id');

        return $this->postJson('/api/v1/mcp', $message, $headers + ['Mcp-Session-Id' => $session])->assertOk();
    }
}
