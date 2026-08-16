<?php

namespace Tests\Feature;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Models\AgentApiAudit;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiStructuredImportTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
        Storage::fake('s3');
        Queue::fake();
    }

    public function test_document_import_creation_is_idempotent_bounded_and_patient_scoped(): void
    {
        $owner = $this->user('import-owner@example.test');
        $other = $this->user('import-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Import Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Import Patient');
        $document = $this->document($patient, $owner, 'synthetic-source.pdf');
        $hiddenDocument = $this->document($hiddenPatient, $other, 'synthetic-hidden.pdf');
        $client = $this->client('Synthetic Import Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $created = $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertAccepted()
            ->assertJsonPath('resource_type', 'import_job')
            ->assertJsonPath('patient_id', $patient->id)
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.document_id', $document->id)
            ->assertJsonPath('data.status', 'pending')
            ->json();

        $job = GenAiImportJob::query()->sole();
        $this->assertSame($job->id, $created['data']['id']);
        $this->assertSame($job->id, $document->refresh()->genai_job_id);
        Storage::disk('s3')->assertExists($job->s3_path);
        Queue::assertPushed(ParseImportJob::class, 1);

        $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.id', $job->id);
        $this->assertDatabaseCount('genai_import_jobs', 1);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
        Queue::assertPushed(ParseImportJob::class, 1);

        $this->postJson("/api/v1/patients/{$hiddenPatient->id}/imports", [
            'document_id' => $hiddenDocument->id,
        ])->assertNotFound();

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports?limit=1&status=pending")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('pagination.limit', 1);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.document_id', $document->id)
            ->assertJsonPath('data.results', []);
        $this->getJson("/api/v1/patients/{$hiddenPatient->id}/imports/{$job->id}")->assertNotFound();

        $audit = json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-source.pdf', $audit);
        $this->assertStringNotContainsString($job->s3_path, $audit);
    }

    public function test_import_review_is_atomic_idempotent_scope_separated_and_cross_job_safe(): void
    {
        $owner = $this->user('review-owner@example.test');
        $manager = $this->user('review-manager@example.test');
        $patient = $this->patient($owner, 'Synthetic Review Patient');
        $this->grant($patient, $owner, $manager, PhrPatientUserAccess::LEVEL_MANAGER);
        $document = $this->document($patient, $owner, 'synthetic-review.pdf');
        $job = $this->linkedJob($document, $owner, 'parsed');
        $accepted = $this->proposal($job, 0, [
            'external_id' => 'synthetic-lab-proposal',
            'test_name' => 'Synthetic panel',
            'analyte' => 'Synthetic analyte',
            'value' => '1.0',
        ]);
        $rejected = $this->proposal($job, 1, ['analyte' => 'Synthetic rejected analyte']);
        $otherDocument = $this->document($patient, $owner, 'synthetic-other-review.pdf');
        $otherJob = $this->linkedJob($otherDocument, $owner, 'parsed');
        $otherResult = $this->proposal($otherJob, 0, ['analyte' => 'Synthetic other analyte']);
        $client = $this->client('Synthetic Review Client');

        Passport::actingAs($manager, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $response = $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$accepted->id}/review", [
            'action' => 'accept',
        ])->assertOk()
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('import.created', 1)
            ->assertJsonPath('data.id', $accepted->id)
            ->assertJsonPath('data.status', 'imported')
            ->assertJsonCount(2, 'data');
        $response->assertJsonMissingPath('data.data');
        $this->assertDatabaseCount('phr_lab_results', 1);
        $this->assertSame($document->id, PhrLabResult::query()->sole()->source_document_id);
        $this->assertSame('parsed', $job->refresh()->status);

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$accepted->id}/review", [
            'action' => 'accept',
        ])->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('import.created', 0);
        $this->assertDatabaseCount('phr_lab_results', 1);

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$rejected->id}/review", [
            'action' => 'reject',
        ])->assertOk()->assertJsonPath('outcome', 'rejected');
        $this->assertSame('imported', $job->refresh()->status);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$rejected->id}/review", [
            'action' => 'reject',
        ])->assertOk()->assertJsonPath('outcome', 'unchanged');

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$otherResult->id}/review", [
            'action' => 'reject',
        ])->assertNotFound();
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$otherJob->id}/results/{$otherResult->id}/review", [
            'action' => 'reject',
            'payload' => ['not' => 'allowed'],
        ])->assertUnprocessable();
    }

    public function test_import_routes_enforce_independent_scopes_and_writable_grants(): void
    {
        $owner = $this->user('import-auth-owner@example.test');
        $viewer = $this->user('import-auth-viewer@example.test');
        $other = $this->user('import-auth-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Import Authorization Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Authorization Patient');
        $this->grant($patient, $owner, $viewer, PhrPatientUserAccess::LEVEL_VIEWER);
        $document = $this->document($patient, $owner, 'synthetic-authorization.pdf');
        $client = $this->client('Synthetic Import Authorization Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports")->assertForbidden();

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertForbidden();
        $this->getJson("/api/v1/patients/{$hiddenPatient->id}/imports")->assertNotFound();

        Passport::actingAs($viewer, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('genai_import_jobs', 0);
    }

    public function test_import_reads_fail_closed_when_job_context_disagrees_with_document_ownership(): void
    {
        $owner = $this->user('mismatched-import-owner@example.test');
        $other = $this->user('mismatched-import-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Mismatched Import Patient');
        $otherPatient = $this->patient($other, 'Synthetic Mismatched Other Patient');
        $document = $this->document($patient, $owner, 'synthetic-mismatched.pdf');
        $job = $this->linkedJob($document, $owner, 'parsed');
        $job->update(['context_json' => json_encode(['patient_id' => $otherPatient->id], JSON_THROW_ON_ERROR)]);
        $client = $this->client('Synthetic Mismatched Import Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports")
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")->assertNotFound();
    }

    public function test_failed_import_retry_clears_unreviewed_output_and_dispatches_once(): void
    {
        $owner = $this->user('retry-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Retry Patient');
        $document = $this->document($patient, $owner, 'synthetic-retry.pdf');
        $job = $this->linkedJob($document, $owner, 'failed', [
            'retry_count' => 1,
            'error_message' => 'Synthetic private provider failure.',
            'raw_response' => 'Synthetic private raw response.',
        ]);
        $proposal = $this->proposal($job, 0, ['analyte' => 'Synthetic stale proposal']);
        $client = $this->client('Synthetic Retry Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.failure_code', 'processing_failed')
            ->assertJsonMissing(['Synthetic private provider failure.', 'Synthetic private raw response.']);

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('outcome', 'retried')
            ->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseMissing('genai_import_results', ['id' => $proposal->id]);
        $job->refresh();
        $this->assertNull($job->error_message);
        $this->assertNull($job->raw_response);
        Queue::assertPushed(ParseImportJob::class, 1);

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');
        Queue::assertPushed(ParseImportJob::class, 1);

        $job->update(['status' => 'failed', 'retry_count' => GenAiImportJob::MAX_RETRIES]);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")->assertConflict();
    }

    public function test_failed_import_with_a_reviewed_result_cannot_be_retried(): void
    {
        $owner = $this->user('reviewed-retry-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Reviewed Retry Patient');
        $document = $this->document($patient, $owner, 'synthetic-reviewed-retry.pdf');
        $job = $this->linkedJob($document, $owner, 'failed', ['retry_count' => 1]);
        $reviewed = $this->proposal($job, 0, ['analyte' => 'Synthetic reviewed proposal']);
        $reviewed->markSkipped();
        $client = $this->client('Synthetic Reviewed Retry Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")->assertConflict();

        $this->assertDatabaseHas('genai_import_results', [
            'id' => $reviewed->id,
            'status' => 'skipped',
        ]);
        Queue::assertNothingPushed();
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Import User',
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
        $this->grant($patient, $owner, $owner, PhrPatientUserAccess::LEVEL_OWNER);

        return $patient;
    }

    private function grant(PhrPatient $patient, User $granter, User $user, string $level): void
    {
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $level,
            'granted_by_user_id' => $granter->id,
            'granted_at' => now(),
        ]);
    }

    private function document(PhrPatient $patient, User $owner, string $filename): PhrDocument
    {
        $path = "patients/{$patient->id}/documents/synthetic/{$filename}";
        $contents = '%PDF-1.4 synthetic import document '.$filename;
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $contents);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic import document',
            'document_type' => 'lab_report',
            'original_filename' => $filename,
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($contents),
            'file_hash' => hash('sha256', $contents),
            'source' => 'manual_upload',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function linkedJob(PhrDocument $document, User $actor, string $status, array $attributes = []): GenAiImportJob
    {
        $job = GenAiImportJob::query()->create([
            'user_id' => $actor->id,
            'job_type' => 'phr_lab_result',
            'file_hash' => $document->file_hash,
            'original_filename' => $document->original_filename,
            's3_path' => 'genai-import/synthetic/'.$document->id,
            'mime_type' => $document->mime_type,
            'file_size_bytes' => $document->byte_size,
            'context_json' => json_encode([
                'patient_id' => $document->patient_id,
                'document_id' => $document->id,
            ], JSON_THROW_ON_ERROR),
            'status' => $status,
            ...$attributes,
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return $job;
    }

    /** @param array<string, mixed> $payload */
    private function proposal(GenAiImportJob $job, int $index, array $payload): GenAiImportResult
    {
        return GenAiImportResult::query()->create([
            'job_id' => $job->id,
            'result_index' => $index,
            'result_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending_review',
            'produced_by' => 'synthetic',
        ]);
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
