<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\PhrGenAiExecutionModeService;
use App\GenAiProcessor\Support\PhrExternalImportStatusMap;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Models\UserAiConfiguration;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regressions for issues #119 and #120.
 *
 * Both are about PHR import status staying coherent with durable external
 * mailbox state, and both go through the one mapping table in
 * {@see PhrExternalImportStatusMap}.
 */
final class ExternalGenAiStatusCoherenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
    }

    public function test_the_state_map_covers_every_package_status_exactly_once(): void
    {
        $table = [];
        foreach (McpRequestStatus::cases() as $case) {
            $table[$case->value] = [
                'with_live_lease' => PhrExternalImportStatusMap::phrStatusFor($case, true),
                'without_live_lease' => PhrExternalImportStatusMap::phrStatusFor($case, false),
            ];
        }

        // A queued request is waiting for a client; a leased one is only "in
        // flight" while the lease is live; expiry is the one terminal package
        // state the package never delivers, so the map owns it. Completion,
        // terminal failure and cancellation are owned by durable delivery and
        // by the execution-mode transaction, so the map states no opinion.
        $this->assertSame([
            'pending' => ['with_live_lease' => 'pending', 'without_live_lease' => 'pending'],
            'leased' => ['with_live_lease' => 'processing', 'without_live_lease' => 'pending'],
            'completed' => ['with_live_lease' => null, 'without_live_lease' => null],
            'failed' => ['with_live_lease' => null, 'without_live_lease' => null],
            'cancelled' => ['with_live_lease' => null, 'without_live_lease' => null],
            'expired' => ['with_live_lease' => 'failed', 'without_live_lease' => 'failed'],
        ], $table);

        $this->assertSame(['pending', 'processing'], PhrExternalImportStatusMap::NONTERMINAL_PHR_STATUSES);
        $this->assertSame(['parsed', 'imported', 'failed'], PhrExternalImportStatusMap::TERMINAL_PHR_STATUSES);
        $this->assertSame(
            GenAiImportJob::VALID_STATUSES,
            [
                ...PhrExternalImportStatusMap::NONTERMINAL_PHR_STATUSES,
                ...PhrExternalImportStatusMap::TERMINAL_PHR_STATUSES,
                'queued_tomorrow',
            ],
            'Every PHR status must be accounted for by the map or be the API-only deferral state.',
        );
    }

    public function test_a_retryable_external_failure_returns_the_import_to_pending_and_keeps_backoff(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        [$queue, $context] = $this->drainTools($user, $job);

        $claim = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($claim);
        $this->assertSame('processing', $job->refresh()->status);

        $queue->fail($context, (string) $job->mcp_request_id, $claim['request']['lease_token'], 'transient', 'Synthetic transient failure', true);

        $request = McpRequest::query()->findOrFail($job->mcp_request_id);
        $this->assertSame(McpRequestStatus::Pending, $request->status);
        $this->assertSame(1, $request->attempt_count);
        $this->assertTrue($request->available_at->isFuture(), 'The package retry backoff must survive the PHR status write.');

        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertSame(0, $job->retry_count, 'A retryable external failure must not consume a PHR retry.');
    }

    public function test_recovery_leaves_a_backed_off_external_import_to_the_package_queue(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        [$queue, $context] = $this->drainTools($user, $job);
        $claim = $queue->claim($context, 'phr-imports');
        $queue->fail($context, (string) $job->mcp_request_id, $claim['request']['lease_token'], 'transient', 'Synthetic transient failure', true);

        $request = McpRequest::query()->findOrFail($job->mcp_request_id);
        $availableAt = $request->available_at;
        $job->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('genai:requeue-stale')->assertSuccessful();

        $this->assertSame('pending', $job->refresh()->status);
        $this->assertSame(0, $job->retry_count);
        Bus::assertNotDispatched(ParseImportJob::class);
        $request->refresh();
        $this->assertSame(1, $request->attempt_count);
        $this->assertSame($availableAt->timestamp, $request->available_at->timestamp);
    }

    public function test_recovery_returns_a_processing_import_to_pending_when_no_client_holds_a_lease(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        [$queue, $context] = $this->drainTools($user, $job);
        $claim = $queue->claim($context, 'phr-imports');
        $queue->fail($context, (string) $job->mcp_request_id, $claim['request']['lease_token'], 'transient', 'Synthetic transient failure', true);

        // This is the #119 state: a local redispatch claimed the row back to
        // `processing` although the package request is queued, not leased.
        $job->forceFill(['status' => 'processing', 'updated_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('genai:requeue-stale')->assertSuccessful();

        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertSame(0, $job->retry_count);
        $this->assertSame(1, McpRequest::query()->findOrFail($job->mcp_request_id)->attempt_count);
    }

    public function test_an_expired_lease_is_never_reported_as_processing(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        [$queue, $context] = $this->drainTools($user, $job);
        $queue->claim($context, 'phr-imports');
        $this->assertSame('processing', $job->refresh()->status);

        // The package leaves the row `leased` until someone re-claims it, so
        // lease liveness — not the package status alone — decides PHR status.
        McpRequest::query()->whereKey($job->mcp_request_id)->update(['lease_expires_at' => now()->subMinute()]);

        $this->artisan('genai:requeue-stale')->assertSuccessful();

        $this->assertSame('pending', $job->refresh()->status);
        $this->assertSame(McpRequestStatus::Leased, McpRequest::query()->findOrFail($job->mcp_request_id)->status);
    }

    public function test_the_next_successful_claim_resumes_processing_and_completes_the_import(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        [$queue, $context] = $this->drainTools($user, $job);
        $requestId = (string) $job->mcp_request_id;
        $claim = $queue->claim($context, 'phr-imports');
        $queue->fail($context, $requestId, $claim['request']['lease_token'], 'transient', 'Synthetic transient failure', true);
        $this->assertSame('pending', $job->refresh()->status);

        $this->travelTo(McpRequest::query()->findOrFail($requestId)->available_at->addSecond());
        $retry = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($retry);
        $this->assertSame($requestId, $retry['request']['id']);
        $this->assertSame('processing', $job->refresh()->status);
        $this->assertSame(2, McpRequest::query()->findOrFail($requestId)->attempt_count);

        $queue->complete($context, $requestId, $retry['request']['lease_token'], $this->completionResponse());
        $this->artisan('genai:mcp:deliver')->assertSuccessful();

        $this->assertSame('parsed', $job->refresh()->status);
        $this->assertDatabaseCount('genai_import_results', 1);
    }

    public function test_a_terminal_external_failure_is_still_owned_by_durable_delivery(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        [$queue, $context] = $this->drainTools($user, $job);
        $requestId = (string) $job->mcp_request_id;
        McpRequest::query()->whereKey($requestId)->update(['max_attempts' => 1]);
        $claim = $queue->claim($context, 'phr-imports');

        $queue->fail($context, $requestId, $claim['request']['lease_token'], 'fatal', 'Synthetic exhausted failure', true);

        $request = McpRequest::query()->findOrFail($requestId);
        $this->assertSame(McpRequestStatus::Failed, $request->status);
        $this->assertNull(PhrExternalImportStatusMap::phrStatusForRequest($request));
        // The map states no opinion, so the row is still `processing` until the
        // durable `failed` delivery lands — that owner also charges the retry.
        $this->assertSame('processing', $job->refresh()->status);

        $this->artisan('genai:mcp:deliver')->assertSuccessful();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame(1, $job->retry_count);
    }

    public function test_a_completed_external_import_survives_a_stale_api_run_reaching_handoff(): void
    {
        [$user, $job] = $this->apiJobWithConfiguredProvider();
        $interleaved = false;

        Http::fake([
            'api.anthropic.com/v1/files*' => Http::response(['id' => 'file_synthetic'], 200),
            'api.anthropic.com/v1/messages' => function () use ($user, $job, &$interleaved) {
                // Deterministic interleaving: the whole external generation —
                // preference switch, enqueue, claim, completion and durable
                // delivery — runs to completion while the stale API request is
                // still "in flight", before it can reach its handoff branch.
                $this->completeAnExternalGenerationFor($user, $job);
                $interleaved = true;

                return Http::response([
                    'content' => [['type' => 'text', 'text' => json_encode($this->completionResponse()['tool_calls'][0]['input'])]],
                    'usage' => ['input_tokens' => 11, 'output_tokens' => 22],
                ], 200);
            },
        ]);

        (new ParseImportJob($job->id))->handle();

        $this->assertTrue($interleaved, 'The interleaving hook must have run inside the stale API request.');
        $job->refresh();
        $this->assertSame('parsed', $job->status, 'The stale API handoff overwrote a terminal import status.');
        $this->assertNotNull($job->parsed_at);
        $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->execution_mode);
        $this->assertSame(1, $job->mcp_generation);
        $this->assertNotNull($job->mcp_request_id, 'The completed external request must stay linked to its import.');
        $this->assertSame(
            McpRequestStatus::Completed,
            McpRequest::query()->findOrFail($job->mcp_request_id)->status,
        );
        $this->assertDatabaseCount('genai_import_results', 1);
        $this->assertDatabaseHas('genai_import_results', [
            'job_id' => $job->id,
            'status' => 'pending_review',
        ]);
        $this->assertNull($job->raw_response);
    }

    public function test_the_owning_api_generation_still_hands_its_work_to_the_external_queue(): void
    {
        [, $job] = $this->apiJobWithConfiguredProvider();
        $job->forceFill(['status' => 'processing', 'raw_response' => '{"synthetic":true}'])->save();

        $this->assertTrue(PhrExternalImportStatusMap::handOffApiGenerationToExternal($job->id, 0));

        $job->refresh();
        $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->execution_mode);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->raw_response);

        // A second, superseded run of the same generation no longer owns the row.
        $this->assertFalse(PhrExternalImportStatusMap::handOffApiGenerationToExternal($job->id, 0));
    }

    public function test_reconciliation_reaches_a_later_import_behind_a_full_batch_of_settled_rows(): void
    {
        [$user, $mailbox] = $this->externalMailboxOwner();

        // Two older imports the mapping table already agrees with. Reconciling
        // them changes nothing, so an id-ordered batch that does not filter on
        // eligibility re-selects exactly these two on every scheduled run.
        $settled = [
            $this->linkedExternalImport($user, $mailbox, McpRequestStatus::Pending, null, 'pending'),
            $this->linkedExternalImport($user, $mailbox, McpRequestStatus::Pending, null, 'pending'),
        ];
        // A later import whose lease has expired: no client is working it, so
        // the map demands `pending`. It must not be starved by the two above.
        $starved = $this->linkedExternalImport($user, $mailbox, McpRequestStatus::Leased, now()->subMinute(), 'processing');
        $this->assertGreaterThan((int) $settled[1]->id, (int) $starved->id);

        $this->artisan('genai:requeue-stale', ['--batch' => 2])->assertSuccessful();

        $this->assertSame('pending', $starved->refresh()->status, 'A full batch of settled rows starved a later import out of reconciliation.');
        foreach ($settled as $job) {
            $this->assertSame('pending', $job->refresh()->status);
        }
    }

    public function test_recovery_reaches_a_later_stranded_import_behind_a_full_batch_of_queue_owned_rows(): void
    {
        [$user, $mailbox] = $this->externalMailboxOwner();
        $cutoff = now()->subMinutes(30);

        // Queue-owned external imports are always left to the package backoff,
        // and skipping one leaves its `updated_at` untouched, so an unfiltered
        // batch keeps them at the head of the id ordering forever.
        $queueOwned = [
            $this->linkedExternalImport($user, $mailbox, McpRequestStatus::Pending, null, 'pending', $cutoff),
            $this->linkedExternalImport($user, $mailbox, McpRequestStatus::Pending, null, 'pending', $cutoff),
        ];
        $stranded = $this->syntheticImport($user, [
            'status' => 'pending',
            'execution_mode' => GenAiImportJob::EXECUTION_API,
        ], $cutoff);
        $this->assertGreaterThan((int) $queueOwned[1]->id, (int) $stranded->id);

        $this->artisan('genai:requeue-stale', ['--batch' => 2])->assertSuccessful();

        Bus::assertDispatchedTimes(ParseImportJob::class, 1);
        Bus::assertDispatched(
            ParseImportJob::class,
            fn (ParseImportJob $dispatched): bool => $dispatched->jobId === (int) $stranded->id,
        );
        $this->assertTrue($stranded->refresh()->updated_at->greaterThan($cutoff));
        foreach ($queueOwned as $job) {
            $this->assertSame($cutoff->timestamp, $job->refresh()->updated_at->timestamp, 'A skipped queue-owned row must keep the package backoff display state.');
        }
    }

    public function test_reconciliation_ignores_a_stale_request_that_predates_a_live_lease(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        // The snapshot an in-flight enqueue would still be holding: the request
        // as it was before any client claimed it.
        $stale = McpRequest::query()->findOrFail($job->mcp_request_id);
        $this->assertSame(McpRequestStatus::Pending, $stale->status);

        [$queue, $context] = $this->drainTools($user, $job);
        $this->assertIsArray($queue->claim($context, 'phr-imports'));
        $this->assertSame('processing', $job->refresh()->status);

        $this->assertFalse(PhrExternalImportStatusMap::reconcile($stale));

        $this->assertSame('processing', $job->refresh()->status, 'A stale request overwrote a newer status derived from a live lease.');
        $this->assertSame(McpRequestStatus::Leased, McpRequest::query()->findOrFail($job->mcp_request_id)->status);
    }

    public function test_reconciliation_ignores_a_stale_lease_that_the_queue_has_already_released(): void
    {
        [$user, $job] = $this->queuedExternalJob();
        [$queue, $context] = $this->drainTools($user, $job);
        $requestId = (string) $job->mcp_request_id;
        $claim = $queue->claim($context, 'phr-imports');
        $staleLease = McpRequest::query()->findOrFail($requestId);
        $this->assertTrue(PhrExternalImportStatusMap::hasLiveLease($staleLease));

        $queue->fail($context, $requestId, $claim['request']['lease_token'], 'transient', 'Synthetic transient failure', true);
        $this->assertSame('pending', $job->refresh()->status);

        $this->assertFalse(PhrExternalImportStatusMap::reconcile($staleLease));

        $this->assertSame('pending', $job->refresh()->status, 'A stale lease revived `processing` with no client holding the request.');
        $this->assertSame(0, $job->retry_count);
    }

    public function test_a_claim_during_a_re_enqueue_survives_the_reconciliation_that_follows_it(): void
    {
        // A redispatched external import re-enters `enqueue()`, which returns
        // the request it is already linked to. Only a linked import is
        // claimable, so this is the reachable shape of the race: the snapshot
        // is read, a client claims, and the older call still holds the
        // pre-claim instance it is about to reconcile from.
        [$user, $job] = $this->queuedExternalJob();
        $requestId = (string) $job->mcp_request_id;
        $claimed = false;
        $outerDepth = DB::transactionLevel();

        // Deterministic interleaving: the instant the re-enqueue path reads the
        // request back, an external client claims it. The depth guard pins the
        // hook to that committed read rather than to any nested query.
        McpRequest::retrieved(function (McpRequest $request) use ($user, $requestId, $outerDepth, &$claimed): void {
            if ($claimed
                || $request->getKey() !== $requestId
                || DB::transactionLevel() !== $outerDepth
                || $request->status !== McpRequestStatus::Pending) {
                return;
            }
            $claimed = true;
            [$queue, $context] = $this->toolsForMailbox($user, (string) $request->mailbox_id);
            $this->assertIsArray($queue->claim($context, 'phr-imports'));
        });

        (new ParseImportJob((int) $job->id))->handle();

        $this->assertTrue($claimed, 'The interleaving hook must have claimed the request during the re-enqueue.');
        $request = McpRequest::query()->findOrFail($requestId);
        $this->assertSame(McpRequestStatus::Leased, $request->status);
        $this->assertTrue(PhrExternalImportStatusMap::hasLiveLease($request));
        $this->assertSame(
            'processing',
            $job->refresh()->status,
            'A stale enqueue snapshot wrote the import back to `pending` under a live lease.',
        );
    }

    /**
     * Run a complete external generation for this import: switch the account to
     * external processing, let the dispatched successor enqueue the work, then
     * claim, complete and durably deliver it.
     */
    private function completeAnExternalGenerationFor(User $user, GenAiImportJob $job): void
    {
        app(PhrGenAiExecutionModeService::class)->update($user, GenAiImportJob::EXECUTION_EXTERNAL);
        (new ParseImportJob($job->id))->handle();

        $successor = $job->newQuery()->findOrFail($job->id);
        [$queue, $context] = $this->drainTools($user, $successor);
        $claim = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($claim);
        $queue->complete(
            $context,
            (string) $successor->mcp_request_id,
            $claim['request']['lease_token'],
            $this->completionResponse(),
        );
        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $this->assertSame('parsed', $job->newQuery()->findOrFail($job->id)->status);
    }

    /** @return array{McpQueueService, ExecutionContext} */
    private function drainTools(User $user, GenAiImportJob $job): array
    {
        $mailboxId = (string) $job->getConnection()->table('genai_mcp_requests')
            ->where('id', $job->refresh()->mcp_request_id)
            ->value('mailbox_id');

        return $this->toolsForMailbox($user, $mailboxId);
    }

    /** @return array{McpQueueService, ExecutionContext} */
    private function toolsForMailbox(User $user, string $mailboxId): array
    {
        return [app(McpQueueService::class), new ExecutionContext(
            principalKey: sprintf('phr:user:%d:oauth:%s', $user->id, hash('sha256', 'synthetic-'.$user->id)),
            mailboxIds: [$mailboxId],
            scopes: [AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK],
        )];
    }

    /** @return array{User, GenAiImportJob} */
    private function queuedExternalJob(): array
    {
        [$user, $job] = $this->importFixture(GenAiImportJob::EXECUTION_EXTERNAL);
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $this->assertNotNull($job->mcp_request_id);
        $this->assertSame('pending', $job->status);

        return [$user, $job];
    }

    /** @return array{User, GenAiImportJob} */
    private function apiJobWithConfiguredProvider(): array
    {
        [$user, $job] = $this->importFixture(GenAiImportJob::EXECUTION_API);
        UserAiConfiguration::query()->create([
            'user_id' => $user->id,
            'name' => 'Synthetic provider',
            'provider' => 'anthropic',
            'api_key' => 'synthetic-key',
            'model' => 'synthetic-model',
            'is_active' => true,
        ]);

        return [$user, $job->refresh()];
    }

    /**
     * A user with an enabled external mailbox, without the full document
     * fixture: the recovery command only ever reads import rows and the
     * durable request they link to.
     *
     * @return array{User, McpMailbox}
     */
    private function externalMailboxOwner(): array
    {
        $user = User::factory()->create([
            'user_role' => 'user',
            'genai_execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
        ]);
        $mailbox = McpMailbox::query()->create([
            'owner_type' => User::class,
            'owner_id' => (string) $user->id,
            'name' => 'phr-imports',
            'enabled' => true,
        ]);

        return [$user, $mailbox];
    }

    /** An external import linked to a durable request in a chosen package state. */
    private function linkedExternalImport(
        User $user,
        McpMailbox $mailbox,
        McpRequestStatus $requestStatus,
        ?CarbonInterface $leaseExpiresAt,
        string $phrStatus,
        ?CarbonInterface $updatedAt = null,
    ): GenAiImportJob {
        $request = McpRequest::query()->create([
            'mailbox_id' => $mailbox->id,
            'queue' => 'phr-imports',
            'status' => $requestStatus,
            'payload' => ['synthetic' => true],
            'available_at' => now(),
            'leased_at' => $leaseExpiresAt === null ? null : now()->subMinutes(20),
            'lease_expires_at' => $leaseExpiresAt,
        ]);

        return $this->syntheticImport($user, [
            'status' => $phrStatus,
            'execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
            'mcp_request_id' => $request->id,
        ], $updatedAt);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syntheticImport(User $user, array $attributes, ?CarbonInterface $updatedAt = null): GenAiImportJob
    {
        $job = GenAiImportJob::query()->create([
            'user_id' => $user->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', 'synthetic-'.$user->id.'-'.uniqid('', true)),
            'original_filename' => 'synthetic-filler.pdf',
            's3_path' => 'genai-import/'.$user->id.'/synthetic/filler.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($this->documentBytes()),
            ...$attributes,
        ]);
        if ($updatedAt !== null) {
            $job->forceFill(['updated_at' => $updatedAt])->saveQuietly();
        }

        return $job->refresh();
    }

    /** @return array{User, GenAiImportJob} */
    private function importFixture(string $executionMode): array
    {
        $user = User::factory()->create([
            'user_role' => 'user',
            'genai_execution_mode' => $executionMode,
        ]);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $user->id,
            'display_name' => 'Synthetic Coherence Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $user->id,
            'granted_at' => now(),
        ]);
        $path = 'genai-import/'.$user->id.'/synthetic/source.pdf';
        Storage::disk('s3')->put($path, $this->documentBytes());
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            'title' => 'Synthetic coherence document',
            'document_type' => 'lab_report',
            'original_filename' => 'synthetic-coherence.pdf',
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
            'original_filename' => 'synthetic-coherence.pdf',
            's3_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($this->documentBytes()),
            'context_json' => json_encode(['patient_id' => $patient->id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'execution_mode' => $executionMode,
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return [$user, $job];
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
        ];
    }

    private function documentBytes(): string
    {
        return "%PDF-1.4\nSynthetic coherence document\n%%EOF\n";
    }
}
