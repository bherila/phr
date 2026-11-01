<?php

namespace Tests\Feature;

use App\GenAiProcessor\Jobs\ParseImportJob;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/** Signed document uploads: open, PUT, finalize, with the limits of documents.upload. */
final class AgentApiSignedDocumentUploadTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private User $actor;

    private PhrPatient $patient;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        Storage::fake(PhrDocument::STORAGE_DISK);
        Queue::fake();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Upload Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->client = $this->makeClient();
        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE, AgentApiScopes::DOCUMENTS_READ]);
    }

    public function test_the_whole_lifecycle_creates_a_document_and_queues_no_ai(): void
    {
        $session = $this->open()
            ->assertCreated()
            ->assertJsonPath('resource_type', 'document_upload')
            ->assertJsonPath('data.method', 'PUT')
            ->assertJsonPath('data.headers', ['Content-Type' => 'application/pdf'])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.kind', 'eob')
            ->json('data');
        $this->assertMatchesRegularExpression('/\A[0-9A-Z]{26}\z/', $session['upload_id']);
        $this->assertStringContainsString('signature=', $session['url']);
        $this->assertTrue(now()->addMinutes(14)->isBefore($session['expires_at']) && now()->addMinutes(16)->isAfter($session['expires_at']));
        $this->assertSame(0, PhrDocument::query()->count(), 'nothing is a document before finalize');

        // The PUT carries no credential at all: the URL is the capability.
        $this->put($session['url'])->assertStatus(415);
        $this->putBytes($session['url'], self::PDF)
            ->assertOk()
            ->assertJsonPath('data.status', 'uploaded')
            ->assertJsonMissingPath('data.url');
        $this->assertSame(0, PhrDocument::query()->count());

        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE, AgentApiScopes::DOCUMENTS_READ]);
        $finalized = $this->finalize($session['upload_id'])
            ->assertCreated()
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.status', 'finalized')
            ->json('data');

        $document = PhrDocument::query()->sole();
        $this->assertSame($document->id, $finalized['document_id']);
        $this->assertSame('eob-2026-10.pdf', $document->original_filename);
        $this->assertSame(strlen(self::PDF), $document->byte_size);
        $this->assertSame(hash('sha256', self::PDF), $document->file_hash);
        $this->assertSame('insurance', $document->document_type);
        $this->assertSame(['eob'], $document->tags);
        $this->assertSame('agent_upload', $document->source);
        $this->assertSame('agent-client:'.$this->client->id, $document->import_source);
        Storage::disk(PhrDocument::STORAGE_DISK)->assertExists((string) $document->storage_path);
        $this->assertSame(self::PDF, Storage::disk(PhrDocument::STORAGE_DISK)->get((string) $document->storage_path));
        $this->assertNull($document->genai_job_id, 'a signed upload never enqueues AI');
        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles('patients/'.$this->patient->id.'/document-uploads'), 'the staged bytes are gone');
        $this->getJson("/api/v1/patients/{$this->patient->id}/documents/{$document->id}")->assertOk()->assertJsonPath('data.has_file', true);

        // A repeat finalize returns the same document; a second PUT to a used URL is refused.
        $this->finalize($session['upload_id'])->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('data.document_id', $document->id);
        $this->putBytes($session['url'], self::PDF)->assertConflict();
        $this->assertSame(1, PhrDocument::query()->count());
    }

    public function test_documents_upload_never_enqueues_ai_either_and_the_browser_send_to_ai_still_does(): void
    {
        $this->post($this->base(), [
            'external_id' => 'synthetic-small-upload', 'document_type' => 'other',
            'file' => UploadedFile::fake()->createWithContent('small.txt', 'synthetic'),
        ], ['Accept' => 'application/json'])->assertCreated();
        Queue::assertNothingPushed();
        $this->assertNull(PhrDocument::query()->sole()->genai_job_id);

        // The person sending a document to AI from the browser is a different path and queues the job.
        Storage::fake('s3');
        $document = PhrDocument::query()->sole();
        $this->actingAs($this->actor)->postJson("/api/phr/patients/{$this->patient->id}/documents/{$document->id}/process")->assertAccepted();
        Queue::assertPushed(ParseImportJob::class, 1);
    }

    public function test_the_url_is_single_use_and_a_bad_attempt_can_be_retried(): void
    {
        $session = $this->open(['sha256' => hash('sha256', self::PDF)])->assertCreated()->json('data');

        // Wrong content type, too many bytes, too few bytes and a wrong hash are each refused...
        $this->putBytes($session['url'], self::PDF, 'image/png')->assertStatus(415);
        $this->putBytes($session['url'], self::PDF.'x')->assertStatus(413);
        $this->putBytes($session['url'], substr(self::PDF, 0, -1))->assertUnprocessable();
        $this->putBytes($session['url'], str_repeat('A', strlen(self::PDF)))->assertUnprocessable();
        $this->assertSame(PhrDocumentUpload::PENDING, PhrDocumentUpload::query()->sole()->status);
        $this->assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());

        // ...without spending the URL, and once the right bytes arrive it is spent.
        $this->putBytes($session['url'], self::PDF)->assertOk();
        $this->putBytes($session['url'], self::PDF)->assertConflict();
    }

    public function test_the_signature_is_checked_and_a_declared_hash_is_verified_at_finalize(): void
    {
        $session = $this->open()->assertCreated()->json('data');
        $this->putBytes($session['url'].'0', self::PDF)->assertForbidden();
        $this->putBytes(preg_replace('/ulid|[0-9A-Z]{26}/', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $session['url'], 1), self::PDF)->assertForbidden();

        $this->putBytes($session['url'], self::PDF)->assertOk();
        // The staged bytes were swapped after the PUT: finalize notices.
        $path = (string) PhrDocumentUpload::query()->sole()->staging_path;
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, strrev(self::PDF));
        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
        $this->finalize($session['upload_id'])->assertUnprocessable();
        $this->assertSame(0, PhrDocument::query()->count());
    }

    public function test_a_cancel_that_lands_while_the_body_is_received_wins(): void
    {
        $session = $this->open()->assertCreated()->json('data');
        $cancelled = false;
        DB::listen(function ($query) use (&$cancelled): void {
            // The moment the PUT has claimed the session, the person cancels it.
            if (! $cancelled && str_starts_with(strtolower($query->sql), 'update "phr_document_uploads"') && in_array(PhrDocumentUpload::RECEIVING, $query->bindings, true)) {
                $cancelled = true;
                DB::table('phr_document_uploads')->update(['status' => PhrDocumentUpload::CANCELLED]);
            }
        });

        $this->putBytes($session['url'], self::PDF)->assertConflict();
        $this->assertTrue($cancelled);
        $this->assertSame(PhrDocumentUpload::CANCELLED, PhrDocumentUpload::query()->sole()->status);
        $this->assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles(), 'the bytes were not kept');
        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
        $this->finalize($session['upload_id'])->assertConflict();
    }

    public function test_the_collector_does_not_undo_an_upload_that_was_published_after_it_read_the_row(): void
    {
        $session = $this->open()->assertCreated()->json('data');
        $this->putBytes($session['url'], self::PDF)->assertOk();
        $row = PhrDocumentUpload::query()->sole();
        $path = (string) $row->staging_path;

        // The collector read the session as an expired PUT in flight; the PUT then published and renewed it.
        $stale = new PhrDocumentUpload;
        $stale->setRawAttributes([...$row->getAttributes(), 'status' => PhrDocumentUpload::RECEIVING, 'expires_at' => now()->subMinute()->toDateTimeString()]);
        $stale->exists = true;
        $discard = new \ReflectionMethod(SignedDocumentUploadService::class, 'discard');
        $discard->invoke(app(SignedDocumentUploadService::class), $stale, PhrDocumentUpload::EXPIRED);

        $this->assertSame(PhrDocumentUpload::UPLOADED, PhrDocumentUpload::query()->sole()->status);
        Storage::disk(PhrDocument::STORAGE_DISK)->assertExists($path);
        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
        $this->finalize($session['upload_id'])->assertCreated();
    }

    public function test_finalize_before_the_put_is_refused(): void
    {
        $session = $this->open()->assertCreated()->json('data');
        $this->finalize($session['upload_id'])->assertConflict();
    }

    public function test_the_limits_of_documents_upload_apply(): void
    {
        // Disallowed content type, extension that does not match, no bytes, too large, bad hash, unknown kind.
        $this->open(['content_type' => 'application/zip', 'filename' => 'x.zip'])->assertUnprocessable();
        $this->open(['content_type' => 'text/csv', 'filename' => 'x.csv'])->assertUnprocessable();
        $this->open(['filename' => 'eob.exe'])->assertUnprocessable();
        $this->open(['filename' => 'dir/eob.pdf'])->assertUnprocessable();
        $this->open(['byte_size' => 0])->assertUnprocessable();
        $this->open(['byte_size' => PhrDocumentUploadLimits::MAX_BYTES + 1])->assertUnprocessable();
        $this->open(['sha256' => 'not-a-hash'])->assertUnprocessable();
        $this->open(['kind' => 'selfie'])->assertUnprocessable();
        $this->assertSame(0, PhrDocumentUpload::query()->count());
        foreach (['eob', 'pharmacy_receipt', 'rx_label', 'statement', 'other'] as $kind) {
            $this->open(['kind' => $kind])->assertCreated();
        }
        foreach ([['image/jpeg', 'label.jpg'], ['image/png', 'label.png'], ['image/webp', 'l.webp'], ['text/plain', 'n.txt']] as [$type, $name]) {
            $this->open(['content_type' => $type, 'filename' => $name])->assertCreated();
        }
    }

    public function test_the_stored_bytes_must_be_what_the_content_type_says(): void
    {
        $session = $this->open(['content_type' => 'image/png', 'filename' => 'label.png', 'byte_size' => 9])->assertCreated()->json('data');
        $this->putBytes($session['url'], 'not a png', 'image/png')->assertOk();
        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
        $this->finalize($session['upload_id'])->assertUnprocessable();
        $this->assertSame(0, PhrDocument::query()->count());
    }

    public function test_an_expired_upload_is_refused_and_discarded(): void
    {
        $unused = $this->open()->assertCreated()->json('data');
        $uploaded = $this->open()->assertCreated()->json('data');
        $this->putBytes($uploaded['url'], self::PDF)->assertOk();
        $this->assertCount(1, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());

        $this->travel(16)->minutes();
        $this->putBytes($unused['url'], self::PDF)->assertForbidden(); // the signature itself has lapsed
        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
        $this->finalize($uploaded['upload_id'])->assertStatus(410);
        $this->finalize($unused['upload_id'])->assertStatus(410);
        $this->assertSame(0, PhrDocument::query()->count());

        $this->artisan('phr:document-uploads:gc')->assertSuccessful();
        $this->assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles(), 'the staged bytes were deleted');
        $this->assertSame([PhrDocumentUpload::EXPIRED], PhrDocumentUpload::query()->pluck('status')->unique()->values()->all());
        $this->assertNull(PhrDocumentUpload::query()->first()?->staging_path);
    }

    public function test_the_collector_expires_unfinalized_uploads_and_drops_old_rows(): void
    {
        $session = $this->open()->assertCreated()->json('data');
        $this->putBytes($session['url'], self::PDF)->assertOk();
        $this->travel(2)->hours();
        $this->artisan('phr:document-uploads:gc')->assertSuccessful();
        $this->assertSame(PhrDocumentUpload::EXPIRED, PhrDocumentUpload::query()->sole()->status);
        $this->assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());

        $this->travel(8)->days();
        $this->artisan('phr:document-uploads:gc')->assertSuccessful();
        $this->assertSame(0, PhrDocumentUpload::query()->count());
    }

    public function test_a_cancelled_upload_deletes_its_bytes(): void
    {
        $session = $this->open()->assertCreated()->json('data');
        $this->putBytes($session['url'], self::PDF)->assertOk();
        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);

        $this->postJson($this->base()."/uploads/{$session['upload_id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());
        $this->postJson($this->base()."/uploads/{$session['upload_id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->finalize($session['upload_id'])->assertConflict();
        $this->putBytes($session['url'], self::PDF)->assertConflict();
    }

    public function test_the_same_bytes_uploaded_again_resolve_to_the_same_document(): void
    {
        $ids = [];
        foreach ([1, 2] as $attempt) {
            $session = $this->open()->assertCreated()->json('data');
            $this->putBytes($session['url'], self::PDF)->assertOk();
            $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
            $response = $this->finalize($session['upload_id']);
            $ids[] = $response->json('data.document_id');
            $attempt === 1 ? $response->assertCreated() : $response->assertOk()->assertJsonPath('outcome', 'duplicate');
        }
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, PhrDocument::query()->count());
        $this->assertSame([], Storage::disk(PhrDocument::STORAGE_DISK)->allFiles('patients/'.$this->patient->id.'/document-uploads'));
    }

    public function test_scopes_clients_and_patients_are_enforced(): void
    {
        $this->actingWith([AgentApiScopes::DOCUMENTS_READ]);
        $this->open()->assertForbidden();

        $this->actingWith([AgentApiScopes::DOCUMENTS_WRITE]);
        $session = $this->open()->assertCreated()->json('data');
        $this->putBytes($session['url'], self::PDF)->assertOk();

        // Another OAuth client cannot finalize or cancel this connection's upload.
        Passport::actingAs($this->actor, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $this->makeClient('Synthetic Other Client'));
        $this->finalize($session['upload_id'])->assertNotFound();
        $this->postJson($this->base()."/uploads/{$session['upload_id']}/cancel")->assertNotFound();

        // Nor can a user without write access to the patient open one.
        $stranger = User::factory()->create(['user_role' => 'user']);
        Passport::actingAs($stranger, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $this->client);
        $this->open()->assertNotFound();
    }

    // ---- helpers --------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function open(array $overrides = []): TestResponse
    {
        return $this->postJson($this->base().'/upload-access', [
            'kind' => 'eob',
            'filename' => 'eob-2026-10.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => strlen(self::PDF),
            ...$overrides,
        ]);
    }

    private function finalize(string $uploadId): TestResponse
    {
        return $this->postJson($this->base()."/uploads/{$uploadId}/finalize");
    }

    private function putBytes(string $url, string $bytes, string $type = 'application/pdf'): TestResponse
    {
        return $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => $type, 'HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    private function base(): string
    {
        return "/api/v1/patients/{$this->patient->id}/documents";
    }

    /** @param list<string> $scopes */
    private function actingWith(array $scopes): void
    {
        Passport::actingAs($this->actor, $scopes, 'api', $this->client);
    }

    private function makeClient(string $name = 'Synthetic Upload Client'): Client
    {
        return Client::query()->create([
            'name' => $name, 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }
}
