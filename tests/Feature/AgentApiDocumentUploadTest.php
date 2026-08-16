<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiDocumentUploadTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
    }

    public function test_upload_is_client_namespaced_idempotent_and_content_deduplicated(): void
    {
        $actor = $this->user('document-writer@example.test');
        $patient = $this->patient($actor, 'Synthetic Document Upload Patient');
        $client = $this->client('Synthetic Document Writer');
        Passport::actingAs($actor, [
            AgentApiScopes::DOCUMENTS_READ,
            AgentApiScopes::DOCUMENTS_WRITE,
        ], 'api', $client);

        $created = $this->postUpload($patient, 'synthetic-document-001', '%PDF-1.4 synthetic first')
            ->assertCreated()
            ->assertJsonPath('resource_type', 'document')
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.source', 'agent_upload')
            ->assertJsonPath('data.import_source', 'agent-client:'.$client->id)
            ->assertJsonPath('data.external_id', 'synthetic-document-001')
            ->assertJsonPath('data.processing_state', 'not_requested')
            ->json();
        $this->assertArrayNotHasKey('storage_path', $created['data']);
        $this->assertArrayNotHasKey('file_hash', $created['data']);

        $document = PhrDocument::query()->sole();
        $storagePath = $document->storage_path;
        $this->assertIsString($storagePath);
        Storage::disk(PhrDocument::STORAGE_DISK)->assertExists($storagePath);

        $this->postUpload($patient, 'synthetic-document-001', '%PDF-1.4 synthetic first')
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.id', $created['data']['id']);
        $this->assertDatabaseCount('phr_documents', 1);
        $this->assertCount(1, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());

        $this->postUpload($patient, 'synthetic-document-002', '%PDF-1.4 synthetic first')
            ->assertConflict();
        $this->assertDatabaseCount('phr_documents', 1);

        $this->postUpload($patient, 'synthetic-document-001', '%PDF-1.4 synthetic changed')
            ->assertConflict();
        $this->assertSame($storagePath, $document->fresh()?->storage_path);
        $this->assertCount(1, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());

        $auditJson = json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic first', $auditJson);
        $this->assertStringNotContainsString('synthetic-document-001', $auditJson);
    }

    public function test_write_only_scope_returns_a_safe_receipt_for_existing_documents(): void
    {
        $owner = $this->user('document-receipt-owner@example.test');
        $manager = $this->user('document-receipt-manager@example.test');
        $patient = $this->patient($owner, 'Synthetic Write Receipt Patient');
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $manager->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $client = $this->client('Synthetic Shared Receipt Client');

        Passport::actingAs($owner, [
            AgentApiScopes::DOCUMENTS_READ,
            AgentApiScopes::DOCUMENTS_WRITE,
        ], 'api', $client);
        $documentId = $this->postUpload($patient, 'synthetic-private-metadata')
            ->assertCreated()
            ->json('data.id');
        $this->assertIsInt($documentId);

        Passport::actingAs($manager, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $client);
        $response = $this->postUpload($patient, 'synthetic-private-metadata')
            ->assertOk()
            ->assertJsonPath('data.id', $documentId)
            ->assertJsonPath('data.patient_id', $patient->id)
            ->assertJsonPath('data.processing_state', 'not_requested')
            ->assertJsonCount(3, 'data');
        foreach (['title', 'summary', 'observed_at', 'tags', 'external_id', 'original_filename'] as $privateField) {
            $response->assertJsonMissingPath('data.'.$privateField);
        }

        $this->postUpload($patient, 'synthetic-private-content-alias')->assertConflict();
    }

    public function test_upload_requires_its_own_scope_and_a_writable_patient(): void
    {
        $owner = $this->user('document-owner@example.test');
        $viewer = $this->user('document-viewer@example.test');
        $other = $this->user('document-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Scoped Upload Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Upload Patient');
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $client = $this->client('Synthetic Scoped Writer');

        Passport::actingAs($owner, [AgentApiScopes::DOCUMENTS_READ], 'api', $client);
        $this->postUpload($patient, 'synthetic-scope-001')->assertForbidden();

        Passport::actingAs($owner, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $client);
        $this->postUpload($hiddenPatient, 'synthetic-hidden-001')->assertNotFound();

        Passport::actingAs($viewer, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $client);
        $this->postUpload($patient, 'synthetic-viewer-001')->assertForbidden();
        $this->assertDatabaseCount('phr_documents', 0);
    }

    public function test_identical_external_ids_from_different_clients_are_isolated(): void
    {
        $actor = $this->user('document-client-isolation@example.test');
        $patient = $this->patient($actor, 'Synthetic Client Isolation Patient');
        $first = $this->client('Synthetic Upload Client A');
        $second = $this->client('Synthetic Upload Client B');

        Passport::actingAs($actor, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $first);
        $this->postUpload($patient, 'synthetic-shared-id')->assertCreated();
        Passport::actingAs($actor, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $second);
        $this->postUpload($patient, 'synthetic-shared-id')->assertCreated();

        $this->assertDatabaseCount('phr_documents', 2);
        $this->assertCount(2, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());
        $this->assertSame(2, PhrDocument::query()->distinct()->count('import_source'));
    }

    public function test_soft_deleted_identity_and_invalid_file_fail_closed(): void
    {
        $actor = $this->user('document-conflict@example.test');
        $patient = $this->patient($actor, 'Synthetic Deleted Identity Patient');
        $client = $this->client('Synthetic Conflict Client');
        Passport::actingAs($actor, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $client);

        $this->postUpload($patient, 'synthetic-deleted-id')->assertCreated();
        PhrDocument::query()->sole()->delete();
        $this->postUpload($patient, 'synthetic-deleted-id')->assertConflict();

        $this->post("/api/v1/patients/{$patient->id}/documents", [
            'external_id' => 'synthetic-invalid-file',
            'file' => UploadedFile::fake()->createWithContent('synthetic.exe', 'not an accepted document'),
            'document_type' => 'other',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('phr_documents', 1);
    }

    public function test_retry_of_an_identity_with_a_missing_blob_fails_closed(): void
    {
        $actor = $this->user('document-missing-blob@example.test');
        $patient = $this->patient($actor, 'Synthetic Missing Blob Patient');
        $client = $this->client('Synthetic Missing Blob Client');
        Passport::actingAs($actor, [AgentApiScopes::DOCUMENTS_WRITE], 'api', $client);

        $this->postUpload($patient, 'synthetic-missing-blob')->assertCreated();
        $document = PhrDocument::query()->sole();
        $this->assertIsString($document->storage_path);
        Storage::disk(PhrDocument::STORAGE_DISK)->delete($document->storage_path);

        $this->postUpload($patient, 'synthetic-missing-blob')
            ->assertConflict()
            ->assertJsonMissingPath('data');
        $this->assertDatabaseCount('phr_documents', 1);
        $this->assertSame($document->id, PhrDocument::query()->sole()->id);
        $this->assertCount(0, Storage::disk(PhrDocument::STORAGE_DISK)->allFiles());
    }

    private function postUpload(PhrPatient $patient, string $externalId, string $contents = '%PDF-1.4 synthetic document'): TestResponse
    {
        return $this->post("/api/v1/patients/{$patient->id}/documents", [
            'external_id' => $externalId,
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', $contents),
            'title' => 'Synthetic document title',
            'document_type' => 'lab_report',
            'observed_at' => '2026-08-16 12:00:00',
            'summary' => 'Synthetic document summary',
            'tags' => ['synthetic', 'example'],
        ], ['Accept' => 'application/json']);
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Document Writer',
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
