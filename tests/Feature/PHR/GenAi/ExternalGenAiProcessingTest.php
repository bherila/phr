<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\External\PhrMcpAttachmentResolver;
use App\GenAiProcessor\External\PhrMcpCompletionDelivery;
use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use League\OAuth2\Server\ResourceServer;
use Mockery;
use Tests\TestCase;

final class ExternalGenAiProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
    }

    public function test_subscription_client_claims_streams_and_idempotently_delivers_a_review_proposal(): void
    {
        [$user, $patient, $document, $job] = $this->externalJob();

        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertSame('mcp', $job->ai_provider);
        $this->assertNotNull($job->mcp_request_id);
        $requestId = $job->mcp_request_id;
        $mailboxId = (string) $job->getConnection()->table('genai_mcp_requests')->where('id', $requestId)->value('mailbox_id');
        $context = $this->context($user, $mailboxId);
        $queue = app(McpQueueService::class);

        $claim = $queue->claim($context, 'phr-imports', 'synthetic-drain-1');
        $this->assertIsArray($claim);
        $this->assertFalse($claim['empty']);
        $this->assertSame($requestId, $claim['request']['id']);
        $this->assertSame('source-document.pdf', $claim['request']['attachments'][0]['name']);
        $this->assertArrayNotHasKey('base64', $claim['request']['attachments'][0]);
        $this->assertSame('processing', $job->refresh()->status);

        $attachment = McpAttachment::query()->where('request_id', $requestId)->sole();
        $stream = app(PhrMcpAttachmentResolver::class)->readStream($attachment, $context);
        $this->assertSame($this->documentBytes(), stream_get_contents($stream));
        fclose($stream);

        $receipt = $queue->complete(
            $context,
            $requestId,
            $claim['request']['lease_token'],
            [
                'text' => '',
                'tool_calls' => [[
                    'name' => 'submit_phr_import',
                    'input' => [
                        'schema_version' => 'phr_pdf_bundle.v1',
                        'source_document' => [
                            'record_key' => 'synthetic-document',
                            'title' => 'Synthetic document',
                            'document_type' => 'lab_report',
                            'summary' => 'Synthetic summary',
                        ],
                        'records' => [
                            'conditions' => [], 'allergies' => [], 'immunizations' => [],
                            'medications' => [], 'vitals' => [],
                            'lab_results' => [[
                                'record_key' => 'synthetic-analyte',
                                'analyte' => 'Synthetic analyte',
                                'value' => '1.0',
                            ]],
                            'procedures' => [], 'encounters' => [],
                            'portal_messages' => [], 'negative_assertions' => [],
                        ],
                    ],
                ]],
            ],
            ['client' => 'synthetic-harness', 'model' => 'synthetic-model'],
        );
        $this->assertSame('completed', $receipt['status']);
        $this->assertSame('processing', $job->refresh()->status);

        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $job->refresh();
        $this->assertSame('parsed', $job->status);
        $this->assertSame('mcp', $job->ai_provider);
        $this->assertSame('synthetic-model', $job->ai_model);
        $this->assertNull($job->raw_response);
        $this->assertDatabaseHas('genai_import_results', [
            'job_id' => $job->id,
            'result_index' => 0,
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseCount('genai_import_results', 1);

        McpDelivery::query()->where('request_id', $requestId)->update(['acknowledged_at' => null]);
        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $this->assertDatabaseCount('genai_import_results', 1);
        $this->assertSame('parsed', $job->refresh()->status);
        $this->assertFalse($document->refresh()->trashed());
        $this->assertSame($patient->id, $document->patient_id);
    }

    public function test_removed_patient_grant_and_deleted_document_cannot_be_claimed_or_completed(): void
    {
        [$user, $patient, $document, $job] = $this->externalJob(owner: false);
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $mailboxId = (string) $job->getConnection()->table('genai_mcp_requests')->where('id', $job->mcp_request_id)->value('mailbox_id');
        $context = $this->context($user, $mailboxId);
        $queue = app(McpQueueService::class);
        $claim = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($claim);

        PhrPatientUserAccess::query()->where('patient_id', $patient->id)->where('user_id', $user->id)->delete();
        try {
            $queue->complete($context, $job->mcp_request_id, $claim['request']['lease_token'], [
                'tool_calls' => [],
            ]);
            $this->fail('A completion was accepted after the patient grant was removed.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }

        $document->delete();
        $this->travel(16)->minutes();
        $this->assertNull($queue->claim($context, 'phr-imports'));
        $this->assertDatabaseCount('genai_import_results', 0);
    }

    public function test_wrong_user_and_stale_lease_cannot_access_external_work(): void
    {
        [$user, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $mailboxId = (string) $job->getConnection()->table('genai_mcp_requests')->where('id', $job->mcp_request_id)->value('mailbox_id');
        $queue = app(McpQueueService::class);
        $ownerContext = $this->context($user, $mailboxId);
        $claim = $queue->claim($ownerContext, 'phr-imports');
        $other = User::factory()->create(['user_role' => 'user']);
        $otherContext = $this->context($other, $mailboxId);

        try {
            $queue->findAuthorized($otherContext, $job->mcp_request_id);
            $this->fail('Another account accessed the request.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }

        $this->travel(16)->minutes();
        try {
            $queue->complete($ownerContext, $job->mcp_request_id, $claim['request']['lease_token'], ['tool_calls' => []]);
            $this->fail('An expired lease completed the request.');
        } catch (McpQueueException $exception) {
            $this->assertSame(409, $exception->httpStatus);
        }
    }

    public function test_versioned_rest_requires_dedicated_scope_and_streams_with_oauth(): void
    {
        [$user, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();

        // Passport 13 resolves its resource server even when actingAs supplies
        // an already validated AccessToken. Keep this isolated feature test
        // independent from deployment-only signing keys.
        $this->app->instance(ResourceServer::class, Mockery::mock(ResourceServer::class));
        Passport::actingAs($user, [AgentApiScopes::GENAI_READ], 'api');
        $this->getJson('/api/v1/genai/queue/status')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('counts.pending', 1);
        $this->postJson('/api/v1/genai/claims', ['queue' => 'phr-imports'])->assertForbidden();

        Passport::actingAs($user, [AgentApiScopes::GENAI_WORK], 'api');
        $claim = $this->postJson('/api/v1/genai/claims', ['queue' => 'phr-imports'])
            ->assertOk()
            ->assertJsonPath('empty', false)
            ->json();
        $download = $this->get($claim['request']['attachments'][0]['download_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($this->documentBytes(), $download->streamedContent());

        $this->getJson('/api/v1/genai/queue/status')->assertForbidden();
        $this->postJson('/api/v1/genai/claims?access_token=synthetic-secret', ['queue' => 'phr-imports'])
            ->assertBadRequest()
            ->assertDontSee('synthetic-secret');
    }

    public function test_execution_mode_change_reconciles_pending_work_without_api_fallback(): void
    {
        [$user, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = $job->refresh()->mcp_request_id;
        Bus::fake();

        $this->actingAs($user)
            ->putJson('/api/user/ai-execution-mode', ['mode' => 'api'])
            ->assertOk()
            ->assertJsonPath('mode', 'api');

        $job->refresh();
        $this->assertSame(GenAiImportJob::EXECUTION_API, $job->execution_mode);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame('cancelled', $job->getConnection()->table('genai_mcp_requests')->where('id', $requestId)->value('status'));
        Bus::assertDispatched(ParseImportJob::class, fn (ParseImportJob $queued): bool => $queued->jobId === $job->id);

        $this->putJson('/api/user/ai-execution-mode', ['mode' => 'external'])
            ->assertOk()
            ->assertJsonPath('mode', 'external')
            ->assertJsonPath('required_scopes.2', AgentApiScopes::GENAI_WORK);
        $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->refresh()->execution_mode);
        $this->assertGreaterThan(0, $job->mcp_generation);
    }

    public function test_transient_proposal_failure_leaves_durable_completion_unacknowledged(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = $job->refresh()->mcp_request_id;
        $this->assertIsString($requestId);
        $delivery = McpDelivery::query()->create([
            'request_id' => $requestId,
            'type' => 'completed',
            'payload' => [
                'result' => [
                    'tool_calls' => [[
                        'name' => 'submit_phr_import',
                        'input' => [],
                    ]],
                ],
            ],
            'available_at' => now(),
        ]);
        $job->getConnection()->getSchemaBuilder()->drop('genai_import_results');

        try {
            app(PhrMcpCompletionDelivery::class)->deliver($delivery);
            $this->fail('A transient proposal persistence error was acknowledged.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('genai_import_results', $exception->getMessage());
        }

        $this->assertSame('pending', $job->refresh()->status);
        $this->assertNull($delivery->refresh()->acknowledged_at);
    }

    /** @return array{User, PhrPatient, PhrDocument, GenAiImportJob} */
    private function externalJob(bool $owner = true): array
    {
        $user = User::factory()->create([
            'user_role' => 'user',
            'genai_execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
        ]);
        $patientOwner = $owner ? $user : User::factory()->create(['user_role' => 'user']);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $patientOwner->id,
            'display_name' => 'Synthetic External Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $owner ? PhrPatientUserAccess::LEVEL_OWNER : PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $patientOwner->id,
            'granted_at' => now(),
        ]);
        $path = 'genai-import/'.$user->id.'/synthetic/source.pdf';
        Storage::disk('s3')->put($path, $this->documentBytes());
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patientOwner->id,
            'uploaded_by_user_id' => $user->id,
            'title' => 'Synthetic external document',
            'document_type' => 'lab_report',
            'original_filename' => 'synthetic-private-name.pdf',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'patients/'.$patient->id.'/documents/synthetic.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($this->documentBytes()),
            'file_hash' => hash('sha256', $this->documentBytes()),
            'source' => 'manual_upload',
        ]);
        $job = GenAiImportJob::query()->create([
            'user_id' => $user->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', $this->documentBytes()),
            'original_filename' => 'synthetic-private-name.pdf',
            's3_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($this->documentBytes()),
            'context_json' => json_encode(['patient_id' => $patient->id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return [$user, $patient, $document, $job];
    }

    private function context(User $user, string $mailboxId): ExecutionContext
    {
        return new ExecutionContext(
            principalKey: sprintf('phr:user:%d:oauth:%s', $user->id, hash('sha256', 'synthetic-'.$user->id)),
            mailboxIds: [$mailboxId],
            scopes: [AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK],
        );
    }

    private function documentBytes(): string
    {
        return '%PDF-1.4 synthetic external import';
    }
}
