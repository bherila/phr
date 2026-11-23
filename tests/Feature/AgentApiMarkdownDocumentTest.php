<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrDocumentUpload;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrDocumentUploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

/** Markdown documents in every upload transport, and signed uploads that name a document type (phr#407). */
final class AgentApiMarkdownDocumentTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    private const string NOTE = "# Plan of Care\n\nAuthor: Robin Nurse, RN\n\n| Test | Result |\n|---|---|\n| Potassium | 3.2 mmol/L |\n\n- Encourage oral intake\n";

    private User $actor;

    private PhrPatient $patient;

    private Client $client;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        Queue::fake();
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Pat Example', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->client = Client::query()->create([
            'name' => 'Synthetic Markdown Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->actor, [AgentApiScopes::MCP_USE, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE], 'api', $this->client);
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    public function test_the_upload_limits_name_markdown(): void
    {
        $this->assertTrue(PhrDocumentUploadLimits::allows('text/markdown', 'note.md'));
        $this->assertTrue(PhrDocumentUploadLimits::allows('text/markdown', 'NOTE.Markdown'));
        $this->assertTrue(PhrDocumentUploadLimits::allows('text/plain', 'note.md'), 'clients report markdown as plain text');
        $this->assertFalse(PhrDocumentUploadLimits::allows('text/markdown', 'note.txt'));
        $this->assertFalse(PhrDocumentUploadLimits::allows('text/markdown', 'note.html'));
        $this->assertSame('text/markdown', PhrDocumentUploadLimits::storedMimeType('text/plain', 'note.md'));
        $this->assertSame('text/markdown', PhrDocumentUploadLimits::storedMimeType('text/html', 'note.markdown'));
        $this->assertSame('text/plain', PhrDocumentUploadLimits::storedMimeType('text/plain', 'note.txt'));
        $this->assertSame('application/pdf', PhrDocumentUploadLimits::storedMimeType('application/pdf', 'note.md'));
    }

    public function test_a_multipart_upload_stores_markdown_even_when_its_bytes_sniff_as_html_or_code(): void
    {
        foreach ([
            'plan-of-care.md' => self::NOTE,
            'inline-html.markdown' => "<html>\n<p>Inline HTML in a markdown note</p>\n</html>\n\n# Heading\n",
            'code.md' => "#include <stdio.h>\nint main(void) { return 0; }\n",
        ] as $filename => $bytes) {
            $this->post("/api/v1/patients/{$this->patient->id}/documents", [
                'external_id' => 'synthetic-'.$filename,
                'file' => $this->sniffedFile($filename, $bytes),
                'document_type' => 'office_visit_note',
            ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.mime_type', 'text/markdown');
        }

        $this->post("/api/v1/patients/{$this->patient->id}/documents", [
            'external_id' => 'synthetic-binary-md',
            'file' => $this->sniffedFile('photo.md', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')),
            'document_type' => 'other',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
        $this->post("/api/v1/patients/{$this->patient->id}/documents", [
            'external_id' => 'synthetic-zip-md',
            'file' => $this->sniffedFile('archive.md', "PK\x03\x04".str_repeat("\x00", 40)),
            'document_type' => 'other',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_mcp_uploads_markdown_and_reads_it_back(): void
    {
        $uploaded = $this->tool('documents.upload', [
            'patient_id' => $this->patient->id, 'external_id' => 'synthetic-mcp-md', 'filename' => 'plan-of-care.md',
            'content_base64' => base64_encode(self::NOTE), 'document_type' => 'office_visit_note',
        ]);
        $this->assertFalse($uploaded['isError'] ?? false, json_encode($uploaded));
        $this->assertSame('text/markdown', $uploaded['structuredContent']['data']['mime_type']);

        $content = $this->tool('documents.content.get', ['patient_id' => $this->patient->id, 'document_id' => $uploaded['structuredContent']['data']['id']]);
        $this->assertFalse($content['isError'] ?? false, json_encode($content));
        $this->assertSame(self::NOTE, $content['structuredContent']['data']['content']);
        $this->assertSame('text/markdown', $content['structuredContent']['data']['content_format']);
    }

    public function test_the_browser_uploads_markdown_and_previews_it_as_plain_text(): void
    {
        $document = $this->actingAs($this->actor)->post("/api/phr/patients/{$this->patient->id}/documents", [
            'file' => $this->sniffedFile('plan-of-care.md', self::NOTE),
            'document_type' => 'office_visit_note',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('document.mime_type', 'text/markdown')->json('document');

        $preview = $this->actingAs($this->actor)->get("/api/phr/patients/{$this->patient->id}/documents/{$document['id']}/file")->assertOk();
        $this->assertSame('text/plain; charset=utf-8', $preview->headers->get('Content-Type'));
        $this->assertSame(self::NOTE, $preview->streamedContent());
    }

    public function test_a_signed_upload_takes_markdown_and_a_document_type(): void
    {
        $session = $this->open(['content_type' => 'text/markdown', 'filename' => 'plan-of-care.md', 'document_type' => 'office_visit_note'])
            ->assertCreated()->assertJsonPath('data.headers', ['Content-Type' => 'text/markdown'])->json('data');
        $this->assertSame('office_visit_note', PhrDocumentUpload::query()->where('public_id', $session['upload_id'])->value('document_type'));
        $this->call('PUT', $session['url'], [], [], [], ['CONTENT_TYPE' => 'text/markdown', 'HTTP_ACCEPT' => 'application/json'], self::NOTE)->assertOk();

        $documentId = $this->postJson($this->base()."/uploads/{$session['upload_id']}/finalize")->assertCreated()->json('data.document_id');

        $document = PhrDocument::query()->findOrFail($documentId);
        $this->assertSame('office_visit_note', $document->document_type);
        $this->assertSame('text/markdown', $document->mime_type);
        $this->assertSame(['other'], $document->tags, 'kind still sets the tag');

        // Markdown declared as plain text is markdown all the same; without document_type, kind picks the type.
        $plain = $this->open(['kind' => 'rx_label', 'content_type' => 'text/plain', 'filename' => 'label.md', 'byte_size' => 9])->assertCreated()->json('data');
        $this->call('PUT', $plain['url'], [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], '# Label 1')->assertOk();
        $labelId = $this->postJson($this->base()."/uploads/{$plain['upload_id']}/finalize")->assertCreated()->json('data.document_id');
        $label = PhrDocument::query()->findOrFail($labelId);
        $this->assertSame('prescription', $label->document_type);
        $this->assertSame('text/markdown', $label->mime_type);

        $this->open(['document_type' => 'progress_note_typo'])->assertUnprocessable()->assertJsonValidationErrors('document_type');
        $this->open(['content_type' => 'text/markdown', 'filename' => 'plan-of-care.txt'])->assertUnprocessable()->assertJsonValidationErrors('filename');
    }

    public function test_the_signed_upload_tool_advertises_markdown_and_document_types(): void
    {
        $tools = collect($this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');
        $schema = $tools['documents.upload_access_create']['inputSchema'];

        $this->assertContains('text/markdown', $schema['properties']['content_type']['enum']);
        $this->assertSame(PhrDocument::DOCUMENT_TYPES, $schema['properties']['document_type']['enum']);
        $this->assertNotContains('document_type', $schema['required']);
    }

    public function test_a_direct_signed_markdown_upload_finalizes_from_an_object_store(): void
    {
        $this->useObjectStoreDisks();
        $this->useDirectUploads();
        $service = app(SignedDocumentUploadService::class);
        $upload = $service->open($this->patient, (int) $this->actor->id, 'synthetic', [
            'kind' => 'other', 'document_type' => 'office_visit_note', 'filename' => 'plan-of-care.md', 'content_type' => 'text/markdown',
            'byte_size' => strlen(self::NOTE), 'sha256' => hash('sha256', self::NOTE),
        ]);
        $this->assertSame('s3', $upload->transport);
        $this->assertSame(200, $this->putToStore($service->authorization($upload), self::NOTE)['status']);

        $document = $service->finalize($this->patient, 'synthetic', $upload->public_id)['document'];

        $this->assertSame('text/markdown', $document->mime_type);
        $this->assertSame('office_visit_note', $document->document_type);
        $this->assertSame(self::NOTE, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $document->storage_path));
    }

    /**
     * A real file, so its type is sniffed from its bytes the way an upload's is
     * (a fake file reports the type its name implies).
     */
    private function sniffedFile(string $filename, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'phr-markdown-');
        file_put_contents((string) $path, $bytes);

        return new UploadedFile((string) $path, $filename, null, null, true);
    }

    /** @param array<string, mixed> $overrides */
    private function open(array $overrides = []): TestResponse
    {
        return $this->postJson($this->base().'/upload-access', [
            'kind' => 'other',
            'filename' => 'plan-of-care.md',
            'content_type' => 'text/markdown',
            'byte_size' => strlen(self::NOTE),
            ...$overrides,
        ]);
    }

    private function base(): string
    {
        return "/api/v1/patients/{$this->patient->id}/documents";
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

        // These tests read definitions of tools outside the core, so they list every
        // authorized tool; a tools/call never depends on what is listed.
        $path = $message['method'] === 'tools/list' ? '/api/v1/mcp?toolsets=all' : '/api/v1/mcp';

        return $this->postJson($path, $message, $headers + ['Mcp-Session-Id' => $session])->assertOk();
    }
}
