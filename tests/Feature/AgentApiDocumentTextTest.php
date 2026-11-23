<?php

namespace Tests\Feature;

use App\DataTransferObjects\PHR\DocumentTextData;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;

/**
 * documents.create_text and note metadata over REST and MCP (phr#409). Every
 * name and value is synthetic.
 */
final class AgentApiDocumentTextTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;
    use UsesObjectStoreDisks;

    private const string NOTE = "# Daily Progress Note\n\n## Nephrology Follow-Up\n\n**S:** Feels better.\n\n| Test | Result | Flag |\n|---|---|---|\n| Creatinine | 1.8 mg/dL | H |\n| Potassium | 3.2 mmol/L | L |\n\n**A/P:**\n- Continue IV fluids\n- Replete potassium\n";

    private User $actor;

    private PhrPatient $patient;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        Queue::fake();
        $this->useObjectStoreDisks();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Pat Example', 'relationship' => 'self', 'birth_date' => '1950-06-15']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Note Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->actor, [AgentApiScopes::MCP_USE, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE], 'api', $client);
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    public function test_a_markdown_note_is_stored_as_a_file_with_its_text_and_metadata_and_waits_for_review(): void
    {
        $created = $this->createText()->assertCreated()
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.mime_type', 'text/markdown')
            ->assertJsonPath('data.document_type', 'progress_note')
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->assertJsonPath('data.loinc_code', '11506-3')
            ->assertJsonPath('data.source_type_label', 'Daily Progress Note')
            ->assertJsonPath('data.author_name', 'Jordan Fellow, MD')
            ->assertJsonPath('data.author_role', 'Nephrology fellow')
            ->assertJsonPath('data.cosigner_name', 'Casey Consultant, MD')
            // A stated offset is honoured: the instant, in UTC.
            ->assertJsonPath('data.authored_at', '2026-02-11T14:30:00+00:00')
            ->assertJsonPath('data.signed_at', '2026-02-11T21:05:00+00:00')
            ->assertJsonPath('data.doc_status', 'final')
            ->assertJsonPath('data.relates_to', null)
            ->assertJsonPath('data.has_file', true)
            ->json('data');

        $document = PhrDocument::query()->findOrFail($created['id']);
        $this->assertSame(self::NOTE, $document->extracted_text);
        $this->assertSame(hash('sha256', self::NOTE), $document->file_hash);
        $this->assertSame('agent_upload', $document->source);
        $this->assertStringEndsWith('.md', (string) $document->storage_path);
        $this->assertSame(self::NOTE, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $document->storage_path));
        $this->getJson($this->base()."/{$document->id}/content")->assertOk()->assertJsonPath('data.content', self::NOTE)->assertJsonPath('data.content_format', 'text/markdown');
    }

    public function test_the_same_note_again_is_unchanged_and_a_different_body_or_a_second_identity_conflicts(): void
    {
        $id = $this->createText()->assertCreated()->json('data.id');
        $this->createText()->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('data.id', $id);
        $this->createText(['body' => self::NOTE."\nAddendum text slipped in.\n"])->assertConflict();
        $this->createText(['external_id' => 'synthetic-progress-note-copy'])->assertConflict();
        $this->assertSame(1, PhrDocument::query()->where('patient_id', $this->patient->id)->count());
        $this->assertCount(1, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());
    }

    public function test_an_addendum_is_a_new_note_linked_to_the_one_it_appends_to(): void
    {
        $original = $this->createText()->assertCreated()->json('data.id');
        $addendum = $this->createText([
            'external_id' => 'synthetic-progress-note-addendum',
            'title' => 'Addendum to Daily Progress Note',
            'body' => "Addendum: potassium repleted; recheck at 18:00.\n",
            'signed_at' => '2026-02-11T18:10:00Z',
            'relates_to' => ['document_id' => $original, 'relation' => 'appends'],
        ])->assertCreated()->assertJsonPath('data.relates_to', ['document_id' => $original, 'relation' => 'appends', 'reference' => null])->json('data.id');

        $this->getJson($this->base()."/{$addendum}")->assertOk()->assertJsonPath('data.relates_to.document_id', $original);
        // A link must name another document of this patient.
        $this->createText(['external_id' => 'synthetic-bad-link', 'body' => 'x', 'relates_to' => ['document_id' => 999999, 'relation' => 'appends']])->assertUnprocessable();
        $this->createText(['external_id' => 'synthetic-bad-relation', 'body' => 'y', 'relates_to' => ['document_id' => $original, 'relation' => 'signs']])->assertUnprocessable();
        $this->assertSame(2, PhrDocument::query()->where('patient_id', $this->patient->id)->count());
    }

    public function test_the_body_is_bounded_validated_and_within_the_mcp_cap(): void
    {
        $this->assertLessThan((int) config('agent_api.mcp_max_body_bytes', 262_144), DocumentTextData::MAX_BODY_BYTES);
        $this->createText(['body' => str_repeat('a', DocumentTextData::MAX_BODY_BYTES + 1)])->assertUnprocessable();
        $this->createText(['body' => "\xC3\x28"])->assertUnprocessable();
        $this->createText(['body' => '   '])->assertUnprocessable();
        $this->createText(['doc_status' => 'signed'])->assertUnprocessable();
        $this->createText(['loinc_code' => 'progress'])->assertUnprocessable();
        $this->createText(['patient_signature_status' => 'maybe'])->assertUnprocessable();
    }

    public function test_documents_update_edits_note_metadata_and_the_link_and_reopens_review(): void
    {
        $consent = $this->createText(['external_id' => 'synthetic-consent', 'document_type' => 'consent', 'title' => 'Consent for treatment', 'body' => "Consent for treatment.\n", 'loinc_code' => null, 'author_name' => null, 'cosigner_name' => null, 'patient_signature_status' => 'signed', 'patient_signed_at' => '2026-02-09T15:00:00Z'])
            ->assertCreated()->assertJsonPath('data.patient_signature_status', 'signed')->json('data');
        $estimate = $this->createText(['external_id' => 'synthetic-estimate', 'document_type' => 'insurance', 'title' => 'Cost estimate', 'body' => "Estimated cost.\n", 'loinc_code' => null, 'author_name' => null, 'cosigner_name' => null, 'patient_signature_status' => 'to_sign'])
            ->assertCreated()->assertJsonPath('data.patient_signature_status', 'to_sign')->json('data');
        PhrDocument::query()->whereKey($estimate['id'])->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $current = $this->getJson($this->base()."/{$estimate['id']}")->assertOk()->json('data');

        $updated = $this->patchJson($this->base()."/{$estimate['id']}", ['expected_version' => $current['version'], 'data' => [
            'patient_signature_status' => 'signed', 'patient_signed_at' => '2026-02-12', 'author_name' => 'Billing Office',
            'relates_to' => ['document_id' => $consent['id'], 'relation' => 'replaces'],
        ]])->assertOk()->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.review_status', PhrReviewStatus::PENDING)
            ->assertJsonPath('data.patient_signature_status', 'signed')
            ->assertJsonPath('data.patient_signed_at', '2026-02-12T00:00:00+00:00')
            ->assertJsonPath('data.relates_to.relation', 'replaces')->json();

        // The link alone is an effective change, and removing it is too.
        PhrDocument::query()->whereKey($estimate['id'])->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $current = $this->getJson($this->base()."/{$estimate['id']}")->json('data');
        $this->patchJson($this->base()."/{$estimate['id']}", ['expected_version' => $current['version'], 'data' => ['relates_to' => null]])
            ->assertOk()->assertJsonPath('outcome', 'updated')->assertJsonPath('data.relates_to', null)->assertJsonPath('data.review_status', PhrReviewStatus::PENDING);
        $current = $this->getJson($this->base()."/{$estimate['id']}")->json('data');
        $this->patchJson($this->base()."/{$estimate['id']}", ['expected_version' => $current['version'], 'data' => ['relates_to' => null]])
            ->assertOk()->assertJsonPath('outcome', 'unchanged');
        // A link to itself, or one closing a loop, is refused.
        $this->patchJson($this->base()."/{$estimate['id']}", ['expected_version' => $current['version'], 'data' => ['relates_to' => ['document_id' => $estimate['id'], 'relation' => 'appends']]])->assertUnprocessable();
        $consentNow = $this->getJson($this->base()."/{$consent['id']}")->json('data');
        $this->patchJson($this->base()."/{$estimate['id']}", ['expected_version' => $current['version'], 'data' => ['relates_to' => ['document_id' => $consent['id'], 'relation' => 'appends']]])->assertOk();
        $this->patchJson($this->base()."/{$consent['id']}", ['expected_version' => $consentNow['version'], 'data' => ['relates_to' => ['document_id' => $estimate['id'], 'relation' => 'appends']]])->assertUnprocessable();
        $this->assertNotSame($consentNow['version'], $updated['version']);
    }

    public function test_an_empty_relates_to_is_refused_and_null_still_removes_the_link(): void
    {
        $original = $this->createText()->assertCreated()->json('data.id');
        foreach (['object' => new \stdClass, 'list' => []] as $shape => $empty) {
            $this->createText(['external_id' => "synthetic-empty-link-{$shape}", 'body' => "Empty link {$shape}.\n", 'relates_to' => $empty])
                ->assertUnprocessable()->assertJsonValidationErrors(['relates_to']);
        }
        // One key alone is not a link either.
        $this->createText(['external_id' => 'synthetic-half-link', 'body' => "Half a link.\n", 'relates_to' => ['relation' => 'appends']])
            ->assertUnprocessable()->assertJsonValidationErrors(['relates_to']);
        $this->assertSame(1, PhrDocument::query()->where('patient_id', $this->patient->id)->count());

        $addendum = $this->createText(['external_id' => 'synthetic-addendum', 'body' => "Addendum.\n", 'relates_to' => ['document_id' => $original, 'relation' => 'appends']])
            ->assertCreated()->json('data');
        foreach ([new \stdClass, []] as $empty) {
            $this->patchJson($this->base()."/{$addendum['id']}", ['expected_version' => $addendum['version'], 'data' => ['relates_to' => $empty]])
                ->assertUnprocessable()->assertJsonValidationErrors(['data.relates_to']);
        }
        $this->patchJson($this->base()."/{$addendum['id']}", ['expected_version' => $addendum['version'], 'data' => ['relates_to' => ['document_id' => $original]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['data.relates_to']);
        // The browser's metadata edit shares the rules.
        $this->actingAs($this->actor)->patchJson("/api/phr/patients/{$this->patient->id}/documents/{$addendum['id']}", ['relates_to' => new \stdClass])
            ->assertUnprocessable()->assertJsonValidationErrors(['relates_to']);
        $this->assertSame($original, $this->getJson($this->base()."/{$addendum['id']}")->json('data.relates_to.document_id'), 'nothing was applied');

        // MCP checks its input schema, which requires both keys, before the request is sent.
        foreach ([new \stdClass, []] as $empty) {
            $this->assertMcpRefusesRelatesTo('documents.create_text', ['patient_id' => $this->patient->id, 'external_id' => 'synthetic-mcp-empty-link', 'document_type' => 'progress_note', 'body' => "MCP.\n", 'relates_to' => $empty]);
            $this->assertMcpRefusesRelatesTo('documents.update', ['patient_id' => $this->patient->id, 'document_id' => $addendum['id'], 'expected_version' => $addendum['version'], 'data' => ['relates_to' => $empty]]);
        }
        $this->assertSame(2, PhrDocument::query()->where('patient_id', $this->patient->id)->count());

        // null still removes the link.
        $this->patchJson($this->base()."/{$addendum['id']}", ['expected_version' => $addendum['version'], 'data' => ['relates_to' => null]])
            ->assertOk()->assertJsonPath('outcome', 'updated')->assertJsonPath('data.relates_to', null);
    }

    public function test_a_relation_only_change_moves_the_version_within_the_same_second(): void
    {
        // Every write lands in the same second, so updated_at cannot tell them apart.
        Carbon::setTestNow(Carbon::parse('2026-02-12 10:00:00.250', 'UTC'));
        try {
            $first = $this->createText(['external_id' => 'synthetic-first-note', 'body' => "First note.\n"])->assertCreated()->json('data.id');
            $second = $this->createText(['external_id' => 'synthetic-second-note', 'body' => "Second note.\n"])->assertCreated()->json('data.id');
            $note = $this->createText(['external_id' => 'synthetic-linked-note', 'body' => "Linked note.\n"])->assertCreated()->json('data');
            $url = $this->base()."/{$note['id']}";
            $loaded = $this->getJson($url)->assertOk()->json('data.version');
            $browserLoaded = $this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/documents/{$note['id']}")->assertOk()->json('document.version');
            $this->assertSame($loaded, $browserLoaded, 'the browser and the API report one version');

            $linked = $this->patchJson($url, ['expected_version' => $loaded, 'data' => ['relates_to' => ['document_id' => $first, 'relation' => 'appends']]])
                ->assertOk()->assertJsonPath('outcome', 'updated')->json();
            $this->assertNotSame($loaded, $linked['version'], 'the link is part of the version');
            $this->assertSame($linked['version'], $linked['data']['version']);
            $this->assertSame($linked['version'], $this->getJson($url)->json('data.version'), 'the write hands back the version a read reports');

            // Another client still holding the version from before the link is refused.
            $this->patchJson($url, ['expected_version' => $loaded, 'data' => ['relates_to' => ['document_id' => $second, 'relation' => 'replaces']]])->assertConflict();
            $stale = $this->tool('documents.update', ['patient_id' => $this->patient->id, 'document_id' => $note['id'], 'expected_version' => $loaded, 'data' => ['relates_to' => null]]);
            $this->assertTrue($stale['isError'] ?? false, (string) json_encode($stale));
            $this->assertSame($first, $this->getJson($url)->json('data.relates_to.document_id'));
            // A reviewer who loaded the page before the link cannot approve what it now says.
            $review = "/api/phr/patients/{$this->patient->id}/documents/{$note['id']}/review";
            $this->actingAs($this->actor)->patchJson($review, ['review_status' => PhrReviewStatus::CONFIRMED, 'version' => $browserLoaded])->assertConflict();
            $listed = collect($this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json('groups'))
                ->flatMap(static fn (array $group): array => $group['items'])->firstWhere('id', $note['id']);
            $this->assertSame($linked['version'], $listed['version'] ?? null, 'the review page lists the current version');
            $this->assertSame($linked['version'], $this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/documents/{$note['id']}")->json('document.version'));

            // Removing the link in the same second moves it again.
            $unlinked = $this->patchJson($url, ['expected_version' => $linked['version'], 'data' => ['relates_to' => null]])->assertOk()->json('version');
            $this->assertNotSame($linked['version'], $unlinked);
            $this->patchJson($url, ['expected_version' => $linked['version'], 'data' => ['relates_to' => ['document_id' => $second, 'relation' => 'appends']]])->assertConflict();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_an_agent_note_leaves_exports_only_once_reviewed_and_never_as_authenticated(): void
    {
        $id = $this->createText()->assertCreated()->json('data.id');
        $data = app(PhrExportDataService::class)->load($this->patient);
        $this->assertStringNotContainsString('phr-document-'.$id, app(PhrFhirExporter::class)->bundleJson($data));
        $this->assertStringNotContainsString('Jordan Fellow', app(PhrCcdaExporter::class)->documentXml($data));

        PhrDocument::query()->whereKey($id)->update(['review_status' => PhrReviewStatus::CONFIRMED]);
        $data = app(PhrExportDataService::class)->load($this->patient);
        $bundle = json_decode(app(PhrFhirExporter::class)->bundleJson($data), true, flags: JSON_THROW_ON_ERROR);
        $reference = collect($bundle['entry'])->pluck('resource')->firstWhere('id', 'phr-document-'.$id);
        $this->assertIsArray($reference);
        $this->assertSame('Jordan Fellow, MD', $reference['author'][0]['display']);
        $this->assertArrayNotHasKey('authenticator', $reference, 'an agent\'s note names a cosigner, but nobody authenticated it');
        $this->assertSame(base64_encode(sha1(self::NOTE, true)), $reference['content'][0]['attachment']['hash']);
        $this->assertSame('text/plain; charset=utf-8', $reference['content'][1]['attachment']['contentType']);
        $this->assertSame(self::NOTE, base64_decode($reference['content'][1]['attachment']['data'], true));

        $xml = app(PhrCcdaExporter::class)->documentXml($data);
        $this->assertStringContainsString('2.16.840.1.113883.10.20.22.4.202', $xml);
        $this->assertStringNotContainsString('typeCode="LA"', $xml);
        $this->assertStringNotContainsString('Casey Consultant', $xml);
    }

    public function test_mcp_creates_a_note_and_offers_the_note_fields(): void
    {
        $list = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/list'])->json('result.tools');
        $tool = collect($list)->firstWhere('name', 'documents_create_text') ?? collect($list)->firstWhere('name', 'documents.create_text');
        $this->assertIsArray($tool);
        $properties = $tool['inputSchema']['properties'];
        $this->assertContains('progress_note', $properties['document_type']['enum']);
        $this->assertContains('amended', $properties['doc_status']['enum']);
        $this->assertSame(['appends', 'replaces'], $properties['relates_to']['properties']['relation']['enum']);
        $this->assertSame(DocumentTextData::MAX_BODY_BYTES, $properties['body']['maxLength']);

        $result = $this->tool((string) $tool['name'], [
            'patient_id' => $this->patient->id, 'external_id' => 'synthetic-mcp-nursing-note', 'document_type' => 'nursing_note',
            'title' => 'Plan of Care', 'body' => "Plan of care: fall precautions.\n", 'loinc_code' => '18776-5',
            'author_name' => 'Robin Nurse, RN', 'authored_at' => '2026-02-11T19:00:00Z', 'signed_at' => '2026-02-11T19:04:00Z',
        ]);
        $this->assertFalse($result['isError'] ?? false, json_encode($result));
        $this->assertSame('18776-5', $result['structuredContent']['data']['loinc_code']);
        $this->assertSame(PhrReviewStatus::PENDING, $result['structuredContent']['data']['review_status']);
        $again = $this->tool((string) $tool['name'], [
            'patient_id' => $this->patient->id, 'external_id' => 'synthetic-mcp-nursing-note', 'document_type' => 'nursing_note',
            'title' => 'Plan of Care', 'body' => "Plan of care: fall precautions.\n",
        ]);
        $this->assertSame('unchanged', $again['structuredContent']['outcome']);
    }

    /** @param array<string, mixed> $overrides */
    private function createText(array $overrides = []): TestResponse
    {
        return $this->postJson($this->base().'/text', array_filter([
            'external_id' => 'synthetic-progress-note',
            'document_type' => 'progress_note',
            'title' => 'Daily Progress Note',
            'body' => self::NOTE,
            'observed_at' => '2026-02-11',
            'loinc_code' => '11506-3',
            'source_type_label' => 'Daily Progress Note',
            'author_name' => 'Jordan Fellow, MD',
            'author_role' => 'Nephrology fellow',
            'cosigner_name' => 'Casey Consultant, MD',
            'authored_at' => '2026-02-11T09:30:00-05:00',
            'signed_at' => '2026-02-11T16:05:00-05:00',
            'doc_status' => 'final',
            ...$overrides,
        ], static fn (mixed $value): bool => $value !== null));
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

    /**
     * The tool call is refused as invalid relates_to, as a JSON-RPC error or a
     * tool error, never a server error.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function assertMcpRefusesRelatesTo(string $tool, array $arguments): void
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
        $refused = $response->json('error.code') === -32602 || $response->json('result.isError') === true;
        $this->assertTrue($refused, $tool.': '.$response->getContent());
        $this->assertStringContainsString('relates_to', (string) $response->getContent(), $tool);
        $this->assertStringNotContainsString('"status":500', (string) $response->getContent(), $tool);
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
