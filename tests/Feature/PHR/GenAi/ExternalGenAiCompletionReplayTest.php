<?php

namespace Tests\Feature\PHR\GenAi;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Characterizes the single narrow authorization relaxation that lets a client
 * whose completion HTTP response was lost replay the identical completion and
 * read the package receipt, and pins every widening that must stay closed.
 */
final class ExternalGenAiCompletionReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
    }

    public function test_identical_replay_after_proposal_delivery_returns_the_original_receipt(): void
    {
        [$user, , , $job, $context, $leaseToken, $receipt] = $this->deliveredCompletion();
        $queue = app(McpQueueService::class);

        // The response never reached the client; it retries well after the lease lapsed.
        $this->travel(16)->minutes();
        $replay = $queue->complete(
            $context,
            $job->mcp_request_id,
            $leaseToken,
            $this->completionResponse(),
            ['client' => 'synthetic-harness', 'model' => 'synthetic-model'],
        );

        $this->assertSame('completed', $replay['status']);
        $this->assertSame($receipt['receipt_id'], $replay['receipt_id']);
        $this->assertSame($receipt['result'], $replay['result']);
        $this->assertSame($receipt['request_id'], $replay['request_id']);
        $this->assertSame('parsed', $job->refresh()->status);
        $this->assertSame($user->id, (int) $job->user_id);
    }

    public function test_replay_creates_no_second_delivery_or_duplicate_proposal(): void
    {
        [, , , $job, $context, $leaseToken] = $this->deliveredCompletion();
        $this->assertDatabaseCount('genai_import_results', 1);
        $this->assertDatabaseCount('genai_mcp_deliveries', 1);

        app(McpQueueService::class)->complete(
            $context,
            $job->mcp_request_id,
            $leaseToken,
            $this->completionResponse(),
            ['client' => 'synthetic-harness', 'model' => 'synthetic-model'],
        );

        // The replay short-circuits before a delivery row is written, and a
        // re-run of the delivery worker still finds nothing to apply.
        $this->assertDatabaseCount('genai_mcp_deliveries', 1);
        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $this->assertDatabaseCount('genai_import_results', 1);
        $this->assertSame('parsed', $job->refresh()->status);
    }

    public function test_replay_with_mutated_completion_data_is_rejected(): void
    {
        [, , , $job, $context, $leaseToken] = $this->deliveredCompletion();
        $queue = app(McpQueueService::class);

        $mutated = $this->completionResponse();
        $mutated['tool_calls'][0]['input']['records']['lab_results'] = [[
            'record_key' => 'injected-analyte',
            'analyte' => 'Injected analyte',
            'value' => '9.9',
        ]];

        try {
            $queue->complete($context, $job->mcp_request_id, $leaseToken, $mutated, [
                'client' => 'synthetic-harness', 'model' => 'synthetic-model',
            ]);
            $this->fail('A mutated completion replay was accepted after delivery.');
        } catch (McpQueueException $exception) {
            $this->assertSame(409, $exception->httpStatus);
        }

        $this->assertDatabaseCount('genai_import_results', 1);
        $stored = (string) $job->getConnection()->table('genai_import_results')
            ->where('job_id', $job->id)
            ->value('result_json');
        $this->assertStringNotContainsString('injected-analyte', $stored);
        $this->assertSame('parsed', $job->refresh()->status);
    }

    public function test_replay_is_insensitive_to_key_order_but_not_to_meaning(): void
    {
        [, , , $job, $context, $leaseToken, $receipt] = $this->deliveredCompletion();
        $reordered = $this->completionResponse();
        $input = $reordered['tool_calls'][0]['input'];
        $reordered['tool_calls'][0]['input'] = array_merge(
            ['records' => $input['records']],
            ['source_document' => array_reverse($input['source_document'], true)],
            ['schema_version' => $input['schema_version']],
        );
        $reordered = ['tool_calls' => $reordered['tool_calls'], 'text' => $reordered['text']];

        $replay = app(McpQueueService::class)->complete(
            $context,
            $job->mcp_request_id,
            $leaseToken,
            $reordered,
            ['model' => 'synthetic-model', 'client' => 'synthetic-harness'],
        );

        $this->assertSame($receipt['receipt_id'], $replay['receipt_id']);
    }

    public function test_replay_with_a_different_lease_token_is_rejected(): void
    {
        [, , , $job, $context] = $this->deliveredCompletion();

        try {
            app(McpQueueService::class)->complete(
                $context,
                $job->mcp_request_id,
                'not-the-original-lease-token',
                $this->completionResponse(),
                ['client' => 'synthetic-harness', 'model' => 'synthetic-model'],
            );
            $this->fail('A replay with a foreign lease token was accepted.');
        } catch (McpQueueException $exception) {
            $this->assertSame(409, $exception->httpStatus);
        }
    }

    public function test_new_claim_after_terminalization_is_still_rejected(): void
    {
        [, , , $job, $context] = $this->deliveredCompletion();
        $queue = app(McpQueueService::class);

        $this->assertNull($queue->claim($context, 'phr-imports'));
        $this->assertNull($queue->claim($context, 'phr-imports', 'replay-idempotency-key'));
        $this->travel(16)->minutes();
        $this->assertNull($queue->claim($context, 'phr-imports'));
        $this->assertSame('parsed', $job->refresh()->status);
        $this->assertDatabaseCount('genai_import_results', 1);
    }

    public function test_lease_renewal_after_terminalization_is_still_rejected(): void
    {
        [, , , $job, $context, $leaseToken] = $this->deliveredCompletion();

        try {
            app(McpQueueService::class)->renew($context, $job->mcp_request_id, $leaseToken);
            $this->fail('A lease was renewed on a delivered terminal request.');
        } catch (McpQueueException $exception) {
            $this->assertSame(409, $exception->httpStatus);
        }
    }

    public function test_failure_submission_after_terminalization_is_still_rejected(): void
    {
        [, , , $job, $context, $leaseToken] = $this->deliveredCompletion();

        try {
            app(McpQueueService::class)->fail(
                $context,
                $job->mcp_request_id,
                $leaseToken,
                'executor_error',
                'Synthetic failure after delivery.',
                false,
            );
            $this->fail('A failure was recorded against a delivered terminal request.');
        } catch (McpQueueException $exception) {
            $this->assertSame(409, $exception->httpStatus);
        }

        $this->assertSame('parsed', $job->refresh()->status);
        $this->assertDatabaseCount('genai_import_results', 1);
    }

    public function test_unrelated_principals_cannot_replay_the_delivered_completion(): void
    {
        [$user, , , $job, , $leaseToken] = $this->deliveredCompletion();
        $queue = app(McpQueueService::class);
        $mailboxId = $this->mailboxId($job);

        // Same account, different OAuth client/credential family.
        $siblingContext = new ExecutionContext(
            principalKey: sprintf('phr:user:%d:oauth:%s', $user->id, hash('sha256', 'sibling-'.$user->id)),
            mailboxIds: [$mailboxId],
            scopes: [AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK],
        );
        // A different account entirely.
        $otherContext = $this->context(User::factory()->create(['user_role' => 'user']), $mailboxId);

        foreach ([$siblingContext, $otherContext] as $context) {
            try {
                $queue->complete($context, $job->mcp_request_id, $leaseToken, $this->completionResponse(), [
                    'client' => 'synthetic-harness', 'model' => 'synthetic-model',
                ]);
                $this->fail('An unrelated principal replayed the delivered completion.');
            } catch (McpQueueException $exception) {
                $this->assertSame(403, $exception->httpStatus);
            }
        }
    }

    public function test_replay_still_requires_the_current_patient_grant_and_source_document(): void
    {
        [$user, $patient, $document, $job, $context, $leaseToken] = $this->deliveredCompletion(owner: false);
        $queue = app(McpQueueService::class);

        PhrPatientUserAccess::query()
            ->where('patient_id', $patient->id)
            ->where('user_id', $user->id)
            ->delete();
        try {
            $queue->complete($context, $job->mcp_request_id, $leaseToken, $this->completionResponse(), [
                'client' => 'synthetic-harness', 'model' => 'synthetic-model',
            ]);
            $this->fail('A replay survived removal of the patient grant.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }

        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $patient->owner_user_id,
            'granted_at' => now(),
        ]);
        $document->delete();
        try {
            $queue->complete($context, $job->mcp_request_id, $leaseToken, $this->completionResponse(), [
                'client' => 'synthetic-harness', 'model' => 'synthetic-model',
            ]);
            $this->fail('A replay survived deletion of the source document.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }
    }

    public function test_replay_is_refused_while_the_job_is_not_in_a_delivered_state(): void
    {
        [, , , $job, $context, $leaseToken] = $this->deliveredCompletion();
        $queue = app(McpQueueService::class);

        foreach (['failed', 'queued_tomorrow'] as $status) {
            GenAiImportJob::query()->whereKey($job->id)->update(['status' => $status]);
            try {
                $queue->complete($context, $job->mcp_request_id, $leaseToken, $this->completionResponse(), [
                    'client' => 'synthetic-harness', 'model' => 'synthetic-model',
                ]);
                $this->fail("A replay was authorized while the job was [{$status}].");
            } catch (McpQueueException $exception) {
                $this->assertSame(403, $exception->httpStatus);
            }
        }
    }

    public function test_replay_is_refused_when_the_durable_delivery_was_never_acknowledged(): void
    {
        [, , , $job, $context, $leaseToken] = $this->deliveredCompletion();
        $job->getConnection()->table('genai_mcp_deliveries')
            ->where('request_id', $job->mcp_request_id)
            ->update(['acknowledged_at' => null]);

        try {
            app(McpQueueService::class)->complete($context, $job->mcp_request_id, $leaseToken, $this->completionResponse(), [
                'client' => 'synthetic-harness', 'model' => 'synthetic-model',
            ]);
            $this->fail('A replay was authorized before durable delivery was acknowledged.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }
    }

    public function test_replay_is_refused_when_the_account_can_no_longer_log_in(): void
    {
        // User ID 1 is implicitly an admin, so keep the replay account off that id.
        User::factory()->create(['user_role' => 'user']);
        [$user, , , $job, $context, $leaseToken] = $this->deliveredCompletion();
        $user->forceFill(['user_role' => ''])->save();
        $this->assertFalse($user->fresh()->canLogin());

        try {
            app(McpQueueService::class)->complete($context, $job->mcp_request_id, $leaseToken, $this->completionResponse(), [
                'client' => 'synthetic-harness', 'model' => 'synthetic-model',
            ]);
            $this->fail('A deactivated account replayed a completion.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }
    }

    public function test_replay_is_refused_when_the_mailbox_is_disabled(): void
    {
        [, , , $job, $context, $leaseToken] = $this->deliveredCompletion();
        $job->getConnection()->table('genai_mcp_mailboxes')
            ->where('id', $this->mailboxId($job))
            ->update(['enabled' => false]);

        try {
            app(McpQueueService::class)->complete($context, $job->mcp_request_id, $leaseToken, $this->completionResponse(), [
                'client' => 'synthetic-harness', 'model' => 'synthetic-model',
            ]);
            $this->fail('A replay was authorized against a disabled mailbox.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }
    }

    /**
     * Drives a job all the way through claim, completion, and durable proposal
     * delivery, leaving the queue request terminal and the job `parsed`.
     *
     * @return array{User, PhrPatient, PhrDocument, GenAiImportJob, ExecutionContext, string, array<string, mixed>}
     */
    private function deliveredCompletion(bool $owner = true): array
    {
        [$user, $patient, $document, $job] = $this->externalJob($owner);
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $context = $this->context($user, $this->mailboxId($job));
        $queue = app(McpQueueService::class);

        $claim = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($claim);
        $leaseToken = $claim['request']['lease_token'];
        $receipt = $queue->complete(
            $context,
            $job->mcp_request_id,
            $leaseToken,
            $this->completionResponse(),
            ['client' => 'synthetic-harness', 'model' => 'synthetic-model'],
        );
        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $this->assertSame('parsed', $job->refresh()->status);

        return [$user, $patient, $document, $job, $context, $leaseToken, $receipt];
    }

    private function mailboxId(GenAiImportJob $job): string
    {
        return (string) $job->getConnection()->table('genai_mcp_requests')
            ->where('id', $job->mcp_request_id)
            ->value('mailbox_id');
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
            'display_name' => 'Synthetic Replay Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $owner ? PhrPatientUserAccess::LEVEL_OWNER : PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $patientOwner->id,
            'granted_at' => now(),
        ]);
        $path = 'genai-import/'.$user->id.'/synthetic-replay/source.pdf';
        Storage::disk('s3')->put($path, $this->documentBytes());
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patientOwner->id,
            'uploaded_by_user_id' => $user->id,
            'title' => 'Synthetic replay document',
            'document_type' => 'lab_report',
            'original_filename' => 'synthetic-private-name.pdf',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'patients/'.$patient->id.'/documents/synthetic-replay.pdf',
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

    /** @return array{text: string, tool_calls: list<array{name: string, input: array<string, mixed>}>} */
    private function completionResponse(): array
    {
        return [
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
                        'extracted_text' => 'Synthetic extracted text',
                    ],
                    'records' => [
                        'conditions' => [], 'allergies' => [], 'immunizations' => [],
                        'medications' => [], 'vitals' => [], 'lab_results' => [],
                        'procedures' => [], 'encounters' => [], 'portal_messages' => [],
                        'negative_assertions' => [],
                    ],
                ],
            ]],
        ];
    }

    private function documentBytes(): string
    {
        return '%PDF-1.4 synthetic external replay import';
    }
}
