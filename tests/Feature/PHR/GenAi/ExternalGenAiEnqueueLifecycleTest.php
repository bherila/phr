<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\PhrExternalEnqueueUnauthorized;
use App\GenAiProcessor\Services\PhrExternalGenAiRequestService;
use App\GenAiProcessor\Services\PhrGenAiExecutionModeService;
use App\GenAiProcessor\Support\PhrExternalImportStatusMap;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Closure;
use FilesystemIterator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

/**
 * The enqueue link lifecycle: an existing external link stays usable only
 * while its authorization holds, and a request that loses the link
 * compare-and-swap never outlives the call that created it.
 */
final class ExternalGenAiEnqueueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $errorLogFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->errorLogFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_deleted_source_document_terminalizes_an_existing_link_and_stops_recovery(): void
    {
        [, , $document, $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;
        $this->assertNotSame('', $requestId);

        $document->delete();

        // A stale-pending recovery redispatch of the same job.
        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame(
            'External processing was cancelled because access or source state changed.',
            $job->error_message,
        );
        $this->assertSame('cancelled', $this->requestStatus($requestId));

        // The loop is over: recovery no longer sees a pending row to redispatch.
        Bus::fake();
        $this->travel(30)->minutes();
        $this->artisan('genai:requeue-stale')->assertSuccessful();
        Bus::assertNotDispatched(ParseImportJob::class);
    }

    public function test_revoked_patient_grant_terminalizes_an_existing_link(): void
    {
        [$user, $patient, , $job] = $this->externalJob(owner: false);
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        PhrPatientUserAccess::query()
            ->where('patient_id', $patient->id)
            ->where('user_id', $user->id)
            ->delete();

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
            $this->fail('An unauthorized existing link was returned for reuse.');
        } catch (PhrExternalEnqueueUnauthorized $exception) {
            $this->assertStringContainsString('patient write grant', $exception->getMessage());
        }

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame('cancelled', $this->requestStatus($requestId));
    }

    public function test_grant_downgraded_to_read_only_terminalizes_an_existing_link(): void
    {
        [$user, $patient, , $job] = $this->externalJob(owner: false);
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        PhrPatientUserAccess::query()
            ->where('patient_id', $patient->id)
            ->where('user_id', $user->id)
            ->update(['access_level' => PhrPatientUserAccess::LEVEL_VIEWER]);

        $this->expectException(PhrExternalEnqueueUnauthorized::class);
        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
        } finally {
            $job->refresh();
            $this->assertSame('failed', $job->status);
            $this->assertNull($job->mcp_request_id);
            $this->assertSame('cancelled', $this->requestStatus($requestId));
        }
    }

    public function test_disabled_owner_account_terminalizes_an_existing_link(): void
    {
        // User 1 is always an admin, so keep the import owner off that id.
        User::factory()->create(['user_role' => 'admin']);
        [$user, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        $user->forceFill(['user_role' => 'disabled'])->save();
        $this->assertFalse($user->refresh()->canLogin());

        $this->expectException(PhrExternalEnqueueUnauthorized::class);
        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
        } finally {
            $this->assertSame('failed', $job->refresh()->status);
            $this->assertSame('cancelled', $this->requestStatus($requestId));
        }
    }

    public function test_transient_access_lookup_failure_keeps_the_existing_link_retryable(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        // A database blip while re-checking the grant is not an authorization
        // loss, so the link and the request must both survive it.
        $this->app->instance(PhrPatientAccessService::class, new class extends PhrPatientAccessService
        {
            public function writablePatient(int $patientId, int $userId): PhrPatient
            {
                throw new QueryException(
                    'mysql',
                    'select * from phr_patients where id = ?',
                    [$patientId],
                    new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'),
                );
            }
        });

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
            $this->fail('A transient access-service failure was treated as success.');
        } catch (QueryException $exception) {
            $this->assertNotInstanceOf(PhrExternalEnqueueUnauthorized::class, $exception);
        }

        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertSame($requestId, $job->mcp_request_id);
        $this->assertSame('pending', $this->requestStatus($requestId));
    }

    public function test_unreachable_staged_document_stays_retryable_instead_of_terminalizing(): void
    {
        [, , , $job] = $this->externalJob();
        // Storage::exists() answers false for an unreachable bucket exactly as
        // it does for a deleted object, so this path must never terminalize.
        Storage::disk('s3')->delete($job->s3_path);

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job);
            $this->fail('A missing staged document was queued for external processing.');
        } catch (RuntimeException $exception) {
            $this->assertNotInstanceOf(PhrExternalEnqueueUnauthorized::class, $exception);
            $this->assertSame('The staged source document is no longer available.', $exception->getMessage());
        }

        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertDatabaseCount('genai_mcp_requests', 0);
    }

    public function test_request_losing_the_link_race_is_cancelled_and_the_successor_survives(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $successorId = (string) $job->refresh()->mcp_request_id;

        $lostId = $this->enqueueLosingTheLinkRace($job, function (McpRequest $created) use ($job, $successorId): void {
            // A successor enqueue won the compare-and-swap first.
            GenAiImportJob::query()->whereKey($job->id)->update(['mcp_request_id' => $successorId]);
        });

        $this->assertNotSame($successorId, $lostId);
        $this->assertSame('cancelled', $this->requestStatus($lostId));
        $this->assertSame('pending', $this->requestStatus($successorId));
        $this->assertSame($successorId, $job->refresh()->mcp_request_id);
    }

    public function test_request_losing_the_link_race_to_an_execution_mode_change_is_cancelled(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $job->refresh();

        $lostId = $this->enqueueLosingTheLinkRace($job, function (McpRequest $created) use ($job): void {
            GenAiImportJob::query()->whereKey($job->id)->update([
                'execution_mode' => GenAiImportJob::EXECUTION_API,
            ]);
        });

        $this->assertSame('cancelled', $this->requestStatus($lostId));
        $this->assertNull($job->refresh()->mcp_request_id);
    }

    public function test_cancelling_a_request_that_lost_the_race_tolerates_a_terminal_request(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $successorId = (string) $job->refresh()->mcp_request_id;

        $lostId = $this->enqueueLosingTheLinkRace($job, function (McpRequest $created) use ($job, $successorId): void {
            GenAiImportJob::query()->whereKey($job->id)->update(['mcp_request_id' => $successorId]);
            // Another actor terminalized the losing request first; cancel()
            // rejects a terminal request and that must not escape enqueue().
            McpRequest::query()->whereKey($created->id)->update([
                'status' => McpRequestStatus::Failed->value,
                'failed_at' => now(),
            ]);
        });

        $this->assertSame('failed', $this->requestStatus($lostId));
        $this->assertSame('pending', $this->requestStatus($successorId));
    }

    public function test_fresh_path_terminalize_fails_an_unauthorized_unlinked_job(): void
    {
        // The positive control for the tightened predicate: nothing concurrent
        // happens, so the fresh path must still terminalize.
        [$user, $patient, , $job] = $this->externalJob(owner: false);
        PhrPatientUserAccess::query()
            ->where('patient_id', $patient->id)
            ->where('user_id', $user->id)
            ->delete();

        $this->expectException(PhrExternalEnqueueUnauthorized::class);
        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job);
        } finally {
            $job->refresh();
            $this->assertSame('failed', $job->status);
            $this->assertSame(0, $job->mcp_generation);
            $this->assertSame(
                'External processing was cancelled because access or source state changed.',
                $job->error_message,
            );
        }
    }

    public function test_fresh_path_terminalize_spares_a_job_a_mode_change_regenerated_as_api(): void
    {
        [$user, , , $job] = $this->externalJob();

        // The mode-change transaction commits between the authorization check
        // and the compare-and-swap. It leaves the job in API mode, pending,
        // with a null link and a bumped generation - which is exactly the state
        // the fresh path started from, so the link predicate alone still
        // matches and would fail the successor's freshly regenerated work.
        $this->interleaveDuringAuthorization(fn () => app(PhrGenAiExecutionModeService::class)
            ->update($user->refresh(), GenAiImportJob::EXECUTION_API));

        $this->expectException(PhrExternalEnqueueUnauthorized::class);
        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
        } finally {
            $job->refresh();
            $this->assertSame(GenAiImportJob::EXECUTION_API, $job->execution_mode);
            $this->assertSame(1, $job->mcp_generation);
            $this->assertSame('pending', $job->status);
            $this->assertNull($job->error_message);
        }
    }

    public function test_fresh_path_terminalize_spares_a_job_regenerated_back_into_external_mode(): void
    {
        [$user, , , $job] = $this->externalJob();

        // Toggling away and back leaves the execution mode identical to the one
        // this call authorized, so only the generation distinguishes the
        // successor's work from ours.
        $this->interleaveDuringAuthorization(function () use ($user): void {
            $modes = app(PhrGenAiExecutionModeService::class);
            $modes->update($user->refresh(), GenAiImportJob::EXECUTION_API);
            $modes->update($user->refresh(), GenAiImportJob::EXECUTION_EXTERNAL);
        });

        $this->expectException(PhrExternalEnqueueUnauthorized::class);
        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
        } finally {
            $job->refresh();
            $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->execution_mode);
            $this->assertSame(2, $job->mcp_generation);
            $this->assertSame('pending', $job->status);
            $this->assertNull($job->error_message);
        }
    }

    public function test_orphan_sweep_cancels_a_terminalized_request_whose_cancellation_failed(): void
    {
        [, , $document, $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        $document->delete();
        $this->failTheFirstCancellationOf($requestId);

        // queueExternally() absorbs the transient failure, so the request id
        // only ever existed on that call stack.
        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->mcp_request_id);
        // The orphan: still pending, still visible, referenced by nothing, and
        // attached to a job that recovery will never redispatch.
        $this->assertSame('pending', $this->requestStatus($requestId));

        $this->travel(20)->minutes();
        $this->artisan('genai:cancel-orphaned-requests')->assertSuccessful();
        $this->assertSame('cancelled', $this->requestStatus($requestId));
    }

    public function test_orphan_sweep_cancels_a_lost_race_request_whose_cancellation_failed(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $successorId = (string) $job->refresh()->mcp_request_id;

        // Free the link and move past the current idempotency key so a second
        // enqueue creates its own request.
        $job->forceFill([
            'mcp_request_id' => null,
            'mcp_generation' => $job->mcp_generation + 1,
            'status' => 'pending',
        ])->save();

        $lostId = null;
        McpRequest::created(function (McpRequest $created) use (&$lostId, $job, $successorId): void {
            if ($lostId !== null) {
                return;
            }
            $lostId = (string) $created->id;
            // A successor enqueue won the compare-and-swap first, and the
            // cancellation of the loser then hits a database blip.
            GenAiImportJob::query()->whereKey($job->id)->update(['mcp_request_id' => $successorId]);
            $this->failTheFirstCancellationOf($lostId);
        });

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
            $this->fail('The losing enqueue reported success.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Connection refused', $exception->getMessage());
        }

        $this->assertIsString($lostId);
        $this->assertNotSame($successorId, $lostId);
        $this->assertSame('pending', $this->requestStatus($lostId));

        $this->travel(20)->minutes();
        $this->artisan('genai:cancel-orphaned-requests')->assertSuccessful();
        $this->assertSame('cancelled', $this->requestStatus($lostId));
        // The successor still owns the link, so the sweep leaves it alone.
        $this->assertSame('pending', $this->requestStatus($successorId));
        $this->assertSame($successorId, $job->refresh()->mcp_request_id);
    }

    public function test_orphan_sweep_spares_linked_and_freshly_created_requests(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        // Linked, and long past the grace period.
        $this->travel(60)->minutes();
        $this->artisan('genai:cancel-orphaned-requests')->assertSuccessful();
        $this->assertSame('pending', $this->requestStatus($requestId));
        $this->travelBack();

        // Unlinked but inside the grace period: an enqueue may still be between
        // creating this request and linking it.
        GenAiImportJob::query()->whereKey($job->id)->update(['mcp_request_id' => null]);
        $this->artisan('genai:cancel-orphaned-requests')->assertSuccessful();
        $this->assertSame('pending', $this->requestStatus($requestId));

        // Past the grace period it is a confirmed orphan.
        $this->travel(20)->minutes();
        $this->artisan('genai:cancel-orphaned-requests')->assertSuccessful();
        $this->assertSame('cancelled', $this->requestStatus($requestId));
    }

    public function test_vanished_link_reset_spares_a_successors_fresh_link(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $vanishedId = (string) $job->refresh()->mcp_request_id;

        // The successor's request, created the ordinary way so it is a genuine
        // queue row rather than a hand-built stand-in.
        $job->forceFill([
            'mcp_request_id' => null,
            'mcp_generation' => $job->mcp_generation + 1,
            'status' => 'pending',
        ])->save();
        $successorId = (string) app(PhrExternalGenAiRequestService::class)->enqueue($job)->id;
        $this->assertNotSame($vanishedId, $successorId);

        // The state this call starts from: linked to the request that is about
        // to vanish, on a generation of its own so the package cannot replay
        // the successor's idempotency key back to it.
        $job->forceFill([
            'mcp_request_id' => $vanishedId,
            'mcp_generation' => $job->refresh()->mcp_generation + 1,
            'status' => 'pending',
        ])->save();

        $this->vanishAndRelinkDuringTheJobRead($job, $vanishedId, $successorId);

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job);
            $this->fail('The stale reset proceeded as though it still owned the row.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The import was relinked while a vanished external request was being cleared.',
                $exception->getMessage(),
            );
        }

        // The successor keeps its link, and no second request was queued
        // against a job this call no longer owned.
        $job->refresh();
        $this->assertSame($successorId, $job->mcp_request_id);
        $this->assertSame('pending', $this->requestStatus($successorId));
        $this->assertDatabaseCount('genai_mcp_requests', 1);
    }

    public function test_vanished_link_reset_requeues_when_nothing_concurrent_happens(): void
    {
        // The positive control for the tightened predicate: the link this call
        // observed is still on the row, so the reset must clear it and requeue.
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $vanishedId = (string) $job->refresh()->mcp_request_id;

        $job->forceFill(['mcp_generation' => $job->mcp_generation + 1])->save();
        $this->vanishDuringTheJobRead($job, $vanishedId);

        $replacement = app(PhrExternalGenAiRequestService::class)->enqueue($job);

        $this->assertNotSame($vanishedId, (string) $replacement->id);
        $job->refresh();
        $this->assertSame((string) $replacement->id, $job->mcp_request_id);
        $this->assertSame('pending', $job->status);
    }

    public function test_expired_lease_at_enqueue_leaves_the_import_pending(): void
    {
        [, , , $job] = $this->externalJob();
        // A client claimed the request the instant it was created and then went
        // away without renewing. The row still reads `leased`, but the package
        // hands an expired lease to the next claimer, so nobody is working it.
        $this->forceCreatedRequestState([
            'status' => McpRequestStatus::Leased->value,
            'lease_expires_at' => now()->subMinutes(5),
        ]);
        // The dispatch claim at the top of ParseImportJob leaves the row here.
        $job->forceFill(['status' => 'processing'])->save();

        $request = app(PhrExternalGenAiRequestService::class)->enqueue($job);

        // Asserted at the enqueue() boundary: no reconciliation has run since,
        // so this is what enqueue() itself wrote.
        $this->assertSame(McpRequestStatus::Leased, $request->refresh()->status);
        $this->assertFalse(PhrExternalImportStatusMap::hasLiveLease($request));
        $this->assertSame('pending', $job->refresh()->status);
    }

    public function test_reused_link_returns_the_import_to_pending_once_its_lease_expires(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        // A client holds a live lease: the import is genuinely in flight.
        McpRequest::query()->whereKey($requestId)->update([
            'status' => McpRequestStatus::Leased->value,
            'lease_expires_at' => now()->addMinutes(5),
        ]);
        app(PhrExternalGenAiRequestService::class)->enqueue($job);
        $this->assertSame('processing', $job->refresh()->status);

        // The lease lapsed without a renewal. The inverse transition is what
        // #119 was missing: nothing could move the row back.
        McpRequest::query()->whereKey($requestId)->update(['lease_expires_at' => now()->subMinute()]);
        app(PhrExternalGenAiRequestService::class)->enqueue($job);
        $this->assertSame('pending', $job->refresh()->status);
    }

    public function test_enqueue_conforms_the_import_to_the_status_map_for_every_queue_state(): void
    {
        // The single-source assertion, stated behaviourally: for every queue
        // state a request can be in when enqueue() reads it, the status the
        // import ends up with is the one PhrExternalImportStatusMap names - and
        // where the map states no opinion, the row is left to its owner.
        $forced = null;
        McpRequest::created(function (McpRequest $created) use (&$forced): void {
            if ($forced === null) {
                return;
            }
            McpRequest::query()->whereKey($created->id)->update($forced);
        });

        foreach ($this->queueStates() as $label => [$attributes, $status, $hasLiveLease]) {
            [, , , $job] = $this->externalJob();
            $forced = $attributes;
            $expected = PhrExternalImportStatusMap::phrStatusFor($status, $hasLiveLease) ?? $job->status;

            app(PhrExternalGenAiRequestService::class)->enqueue($job);

            $this->assertSame($expected, $job->refresh()->status, $label);
        }
    }

    public function test_no_phr_status_is_derived_from_a_package_status_outside_the_map(): void
    {
        // The structural half of the single-source assertion. A PHR status may
        // be compared against anywhere, but it may only be *produced* by a
        // statement that also inspects a package request status inside the one
        // mapping table. That is the exact shape of the two derivations this
        // change removed, and of any copy someone reintroduces later.
        $map = (string) realpath(app_path('GenAiProcessor/Support/PhrExternalImportStatusMap.php'));
        $this->assertFileExists($map);
        $phrStatuses = [
            ...PhrExternalImportStatusMap::NONTERMINAL_PHR_STATUSES,
            ...PhrExternalImportStatusMap::TERMINAL_PHR_STATUSES,
        ];

        $offences = [];
        foreach ($this->phpSourceFiles(app_path()) as $file) {
            if (realpath($file) === $map) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (! str_contains($source, 'McpRequestStatus')) {
                continue;
            }
            foreach ($this->phrStatusesProducedBesidePackageStatus($source, $phrStatuses) as $line) {
                $offences[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.$line;
            }
        }

        $this->assertSame(
            [],
            $offences,
            'PhrExternalImportStatusMap must be the only place a package request status becomes a PHR import status.',
        );
    }

    public function test_vanished_link_reset_proceeds_after_the_foreign_key_cleared_the_link(): void
    {
        // Positive control for the tightened predicate, on the branch the
        // `nullOnDelete` cascade actually produces: the request row is pruned,
        // the foreign key clears `mcp_request_id` with it, and this call's
        // observation of the link survives only in memory. Same mode, same
        // generation, still nonterminal - nothing superseded this work, so it
        // must go on to queue a replacement.
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $vanishedId = (string) $job->refresh()->mcp_request_id;
        $generation = (int) $job->mcp_generation;

        $linkAfterCascade = 'not observed';
        $vanished = false;
        GenAiImportJob::retrieved(function (GenAiImportJob $read) use (&$vanished, &$linkAfterCascade, $job, $vanishedId): void {
            if ($vanished || (int) $read->id !== (int) $job->id) {
                return;
            }
            $vanished = true;
            McpRequest::query()->whereKey($vanishedId)->delete();
            $linkAfterCascade = GenAiImportJob::query()->getConnection()
                ->table('genai_import_jobs')->where('id', $job->id)->value('mcp_request_id');
        });

        $replacement = app(PhrExternalGenAiRequestService::class)->enqueue($job);

        // Stated as an assertion rather than assumed: if foreign keys were not
        // being enforced for this run, the null-link branch of the predicate
        // would never be exercised and this test would prove nothing.
        $this->assertNull(
            $linkAfterCascade,
            'The nullOnDelete cascade did not clear the link, so foreign keys are not being enforced.',
        );
        $this->assertNotSame($vanishedId, (string) $replacement->id);
        $job->refresh();
        $this->assertSame((string) $replacement->id, $job->mcp_request_id);
        $this->assertSame('pending', $job->status);
        $this->assertSame($generation, (int) $job->mcp_generation);
    }

    public function test_vanished_link_reset_leaves_a_same_generation_terminalization_terminal(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $vanishedId = (string) $job->refresh()->mcp_request_id;

        // A concurrent call lost the source authorization and terminalized the
        // row. That write fails the row and clears the link but does *not*
        // bump the generation, so execution mode, generation and a null link
        // all still read exactly as this call captured them: only the status
        // distinguishes an import a user has been told is finished from the
        // never-linked state this call started from.
        $terminalized = false;
        GenAiImportJob::retrieved(function (GenAiImportJob $read) use (&$terminalized, $job, $vanishedId): void {
            if ($terminalized || (int) $read->id !== (int) $job->id) {
                return;
            }
            $terminalized = true;
            McpRequest::query()->whereKey($vanishedId)->delete();
            GenAiImportJob::query()->whereKey($job->id)->update([
                'mcp_request_id' => null,
                'status' => 'failed',
                'error_message' => 'External processing was cancelled because access or source state changed.',
            ]);
        });

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job);
            $this->fail('The stale reset restarted an import that had already been terminalized.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The import was relinked while a vanished external request was being cleared.',
                $exception->getMessage(),
            );
        }

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame(
            'External processing was cancelled because access or source state changed.',
            $job->error_message,
        );
        $this->assertDatabaseCount('genai_mcp_requests', 0);
    }

    public function test_request_losing_the_link_race_to_a_mode_change_that_toggled_back_is_cancelled(): void
    {
        [$user, , , $job] = $this->externalJob();

        $lostId = $this->enqueueLosingTheLinkRace($job, function () use ($user): void {
            // Away and back: the row ends external, pending and unlinked -
            // byte for byte the state this call authorized, except that each
            // transition bumped the generation. Predicated on external mode
            // and a null link alone, this call's request attaches itself to
            // the successor's revision.
            $modes = app(PhrGenAiExecutionModeService::class);
            $modes->update($user->refresh(), GenAiImportJob::EXECUTION_API);
            $modes->update($user->refresh(), GenAiImportJob::EXECUTION_EXTERNAL);
        });

        $job->refresh();
        $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->execution_mode);
        $this->assertSame(3, (int) $job->mcp_generation);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame('pending', $job->status);
        $this->assertSame('cancelled', $this->requestStatus($lostId));
    }

    public function test_request_losing_the_link_race_to_a_retry_is_cancelled(): void
    {
        [, , , $job] = $this->externalJob();

        $lostId = $this->enqueueLosingTheLinkRace($job, function () use ($job): void {
            // The retry path writes exactly this: same execution mode,
            // pending, unlinked, next generation. The generation is the only
            // thing that tells this call's revision from the retry's.
            GenAiImportJob::query()->whereKey($job->id)->update([
                'mcp_request_id' => null,
                'mcp_generation' => DB::raw('mcp_generation + 1'),
                'status' => 'pending',
                'error_message' => null,
            ]);
        });

        $job->refresh();
        $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->execution_mode);
        $this->assertSame(2, (int) $job->mcp_generation);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame('cancelled', $this->requestStatus($lostId));
    }

    public function test_a_superseded_enqueue_attempt_leaves_a_processing_successor_untouched(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $successorId = (string) $job->refresh()->mcp_request_id;
        // The successor is genuinely in flight: a client holds a live lease.
        McpRequest::query()->whereKey($successorId)->update([
            'status' => McpRequestStatus::Leased->value,
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        // The revision the stale attempt is working on behalf of: unlinked,
        // pending, one generation behind what the successor will write.
        $job->forceFill([
            'mcp_request_id' => null,
            'mcp_generation' => 1,
            'status' => 'pending',
        ])->save();

        // The successor lands inside the source re-check, after this attempt
        // captured its revision and before the enqueue fails. Removing the
        // staged document makes that failure a transient RuntimeException -
        // the generic branch of the caller's catch, not the terminalizing one.
        $this->interleaveDuringWritableLookup(function () use ($job, $successorId): void {
            GenAiImportJob::query()->whereKey($job->id)->update([
                'mcp_request_id' => $successorId,
                'mcp_generation' => 2,
                'status' => 'processing',
            ]);
            Storage::disk('s3')->delete($job->s3_path);
        });

        (new ParseImportJob($job->id))->handle();

        // Neither the service nor the caller may demote, annotate or relink
        // the successor's revision.
        $job->refresh();
        $this->assertSame($successorId, $job->mcp_request_id);
        $this->assertSame(2, (int) $job->mcp_generation);
        $this->assertSame('processing', $job->status);
        $this->assertNull($job->error_message);
        $this->assertSame(McpRequestStatus::Leased->value, $this->requestStatus($successorId));
    }

    public function test_same_generation_callers_share_one_request_and_the_loser_cancels_nothing(): void
    {
        // Positive control for the tightened link compare-and-swap, and for
        // the rule that an affected-row count is not an ownership token. Two
        // callers of one revision are handed the same request by the package's
        // idempotency key. The second one's UPDATE matches no rows - the link
        // is already there - but it has lost nothing: that is its own request
        // on the row. Reading the zero as a lost race would cancel the request
        // both callers are using.
        [, , , $job] = $this->externalJob();

        $winnerRequest = null;
        $raced = false;
        GenAiImportJob::retrieved(function (GenAiImportJob $read) use (&$raced, &$winnerRequest, $job): void {
            if ($raced || (int) $read->id !== (int) $job->id) {
                return;
            }
            $raced = true;
            $winnerRequest = app(PhrExternalGenAiRequestService::class)->enqueue(GenAiImportJob::query()->findOrFail($job->id));
        });

        $loserRequest = app(PhrExternalGenAiRequestService::class)->enqueue($job);

        $this->assertInstanceOf(McpRequest::class, $winnerRequest);
        $this->assertSame((string) $winnerRequest->id, (string) $loserRequest->id);
        $this->assertDatabaseCount('genai_mcp_requests', 1);
        $this->assertSame('pending', $this->requestStatus((string) $winnerRequest->id));
        $job->refresh();
        $this->assertSame((string) $winnerRequest->id, $job->mcp_request_id);
        $this->assertSame('pending', $job->status);
    }

    public function test_an_expired_request_is_reported_as_the_failure_it_is_and_charges_no_retry(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;
        $retryCount = (int) $job->retry_count;

        // The package emits no `failed` delivery for a request that ran out of
        // time, so `expired` is the only record of it.
        McpRequest::query()->whereKey($requestId)->update(['status' => McpRequestStatus::Expired->value]);

        // The service half. Handing the McpRequest back rather than throwing is
        // deliberate: the map turns `expired` into `failed`, so the import is
        // terminalized on the spot and the caller reconciles against it.
        $request = app(PhrExternalGenAiRequestService::class)->enqueue($job);
        $this->assertSame($requestId, (string) $request->id);
        $this->assertSame(McpRequestStatus::Expired, $request->status);
        $this->assertSame('failed', $job->refresh()->status);

        // The caller half, from the same observation: stale-pending recovery
        // redispatches the row before that terminal status has landed.
        $job->forceFill(['status' => 'pending', 'error_message' => null])->save();
        $diagnostics = [];
        Log::listen(function (MessageLogged $entry) use (&$diagnostics): void {
            $diagnostics[$entry->message] = $entry->context;
        });

        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame($requestId, $job->mcp_request_id);
        // Neither revived nor charged a PHR retry it never spent.
        $this->assertSame($retryCount, (int) $job->retry_count);

        // The diagnostic reports the outcome the import actually reached. An
        // unconditional "queued for external processing" here would describe a
        // finished, failed import as still in flight - the one line someone
        // debugging a stuck import would take at face value.
        $this->assertArrayNotHasKey('ParseImportJob: queued for external processing', $diagnostics);
        $this->assertArrayHasKey('ParseImportJob: external enqueue reconciled', $diagnostics);
        $this->assertSame(
            ['job_id' => $job->id, 'import_status' => 'failed', 'request_status' => McpRequestStatus::Expired->value],
            $diagnostics['ParseImportJob: external enqueue reconciled'],
        );
    }

    // The recovery tests below force their interleavings deterministically on
    // SQLite `:memory:`, one connection, one process. They pin the predicates
    // and the order of the reads and writes; they are NOT InnoDB concurrency
    // tests, and say nothing about how `lockForUpdate` behaves under real
    // contention.

    public function test_logger_failure_after_a_successful_reused_enqueue_changes_nothing(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;
        $generation = (int) $job->mcp_generation;
        $retryCount = (int) $job->retry_count;

        // A client holds a live lease, so reusing the link and reconciling it
        // is a complete success: the import is genuinely in flight.
        McpRequest::query()->whereKey($requestId)->update([
            'status' => McpRequestStatus::Leased->value,
            'lease_expires_at' => now()->addMinutes(5),
            'attempt_count' => 1,
        ]);
        $leased = $this->requestRow($requestId);
        $diagnostics = $this->captureDiagnostics();
        // Only the diagnostic written after that success fails - a full or
        // read-only log destination, as far as the caller can tell.
        $this->throwWhenLogged('ParseImportJob: external enqueue reconciled');
        $fallback = $this->redirectErrorLog();

        // Returning at all is part of the assertion: nothing escapes handle().
        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('processing', $job->status);
        $this->assertNull($job->error_message);
        $this->assertSame($requestId, $job->mcp_request_id);
        $this->assertSame($generation, (int) $job->mcp_generation);
        $this->assertSame($retryCount, (int) $job->retry_count);
        $this->assertSame($leased, $this->requestRow($requestId));
        // A failed diagnostic is not a failed enqueue, so no business recovery
        // ran and none was reported.
        $this->assertArrayNotHasKey('ParseImportJob: external enqueue deferred to recovery', $diagnostics->context);
        $this->assertArrayNotHasKey('ParseImportJob: external enqueue recovery left the import unchanged', $diagnostics->context);
        // The event itself is not lost: it reaches the fallback sink.
        $this->assertStringContainsString('ParseImportJob: external enqueue reconciled', (string) file_get_contents($fallback));
    }

    public function test_logger_failure_after_an_authorization_terminalization_does_not_escape_the_job(): void
    {
        [, , $document, $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;
        $document->delete();
        $this->throwWhenLogged('ParseImportJob: external import terminalized after authorization was lost');
        $fallback = $this->redirectErrorLog();

        // Returning at all is the assertion: the terminalization is the
        // outcome, and a broken log destination must not turn it into a
        // failed queue job.
        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame(
            'External processing was cancelled because access or source state changed.',
            $job->error_message,
        );
        $this->assertSame('cancelled', $this->requestStatus($requestId));
        $this->assertStringContainsString(
            'ParseImportJob: external import terminalized after authorization was lost',
            (string) file_get_contents($fallback),
        );
    }

    public function test_transient_reuse_failure_while_the_request_gains_a_live_lease_keeps_the_import_processing(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;
        $generation = (int) $job->mcp_generation;
        $retryCount = (int) $job->retry_count;
        $this->assertSame('pending', $job->status);

        // A stale-pending redispatch reuses the link. While it re-checks the
        // source authorization a client claims the request, and the re-check
        // itself then fails transiently.
        $leased = null;
        $this->failTransientlyDuringWritableLookup(function () use ($requestId, &$leased): void {
            McpRequest::query()->whereKey($requestId)->update([
                'status' => McpRequestStatus::Leased->value,
                'lease_expires_at' => now()->addMinutes(5),
                'attempt_count' => 1,
            ]);
            $leased = $this->requestRow($requestId);
        });
        $diagnostics = $this->captureDiagnostics();

        (new ParseImportJob($job->id))->handle();

        // The durable request says a client is working it; the failure of an
        // unrelated re-check does not get to say otherwise.
        $job->refresh();
        $this->assertSame('processing', $job->status);
        $this->assertNull($job->error_message);
        $this->assertSame($requestId, $job->mcp_request_id);
        $this->assertSame($generation, (int) $job->mcp_generation);
        $this->assertSame($retryCount, (int) $job->retry_count);
        // Recovery reads the request; it never writes it.
        $this->assertSame($leased, $this->requestRow($requestId));
        $this->assertSame([
            'job_id' => $job->id,
            'exception' => QueryException::class,
            'deferred' => true,
            'import_status' => 'processing',
            'request_status' => McpRequestStatus::Leased->value,
        ], $diagnostics->context['ParseImportJob: external enqueue deferred to recovery'] ?? null);
    }

    public function test_transient_reuse_failure_conforms_the_import_to_every_unleased_request_state(): void
    {
        foreach ($this->queueStates() as $label => [$attributes, $status, $hasLiveLease]) {
            if ($hasLiveLease) {
                continue;
            }
            // The previous state's failing access service must not stop this
            // state's first enqueue from linking.
            $this->app->forgetInstance(PhrPatientAccessService::class);
            [, , , $job] = $this->externalJob();
            (new ParseImportJob($job->id))->handle();
            $requestId = (string) $job->refresh()->mcp_request_id;
            McpRequest::query()->whereKey($requestId)->update($attributes);
            $before = $this->requestRow($requestId);
            $this->failTransientlyDuringWritableLookup();

            (new ParseImportJob($job->id))->handle();

            $job->refresh();
            $mapped = PhrExternalImportStatusMap::phrStatusFor($status, false);
            // A null answer leaves the row where this run's dispatch claim put
            // it: the delivery or mode change that owns that state writes it.
            $this->assertSame($mapped ?? 'processing', $job->status, $label);
            $this->assertSame(
                $mapped === 'pending' ? 'External processing is temporarily unavailable; the import remains queued.' : null,
                $job->error_message,
                $label,
            );
            $this->assertSame($requestId, $job->mcp_request_id, $label);
            $this->assertSame($before, $this->requestRow($requestId), $label);
        }
    }

    public function test_database_failure_during_linked_recovery_leaves_the_import_untouched(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $requestId = (string) $job->refresh()->mcp_request_id;

        // The re-check fails transiently, and so does the recovery's own read
        // of the request: the database is what is failing.
        $snapshot = null;
        $this->failTransientlyDuringWritableLookup(function () use ($job, $requestId, &$snapshot): void {
            McpRequest::query()->whereKey($requestId)->update([
                'status' => McpRequestStatus::Leased->value,
                'lease_expires_at' => now()->addMinutes(5),
            ]);
            $snapshot = $this->jobRow($job->id);
            $armed = true;
            McpRequest::retrieved(function (McpRequest $read) use (&$armed, $requestId): void {
                if (! $armed || (string) $read->id !== $requestId) {
                    return;
                }
                $armed = false;

                throw $this->transientQueryException();
            });
        });
        $diagnostics = $this->captureDiagnostics();

        (new ParseImportJob($job->id))->handle();

        // Exactly as this run's dispatch claim left it - not a guessed
        // `pending` written over a request nobody could read.
        $this->assertIsArray($snapshot);
        $this->assertSame('processing', $this->jobRow($job->id)['status']);
        $this->assertSame($snapshot, $this->jobRow($job->id));
        $this->assertArrayNotHasKey('ParseImportJob: external enqueue deferred to recovery', $diagnostics->context);
        $this->assertSame([
            'job_id' => $job->id,
            'exception' => QueryException::class,
            'recovery_exception' => QueryException::class,
        ], $diagnostics->context['ParseImportJob: external enqueue recovery left the import unchanged'] ?? null);
    }

    public function test_transient_failure_with_no_link_still_defers_the_import_to_pending(): void
    {
        [, , , $job] = $this->externalJob();
        // Nothing has ever been queued for this revision, so there is no
        // durable request to defer to.
        Storage::disk('s3')->delete($job->s3_path);
        $diagnostics = $this->captureDiagnostics();

        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame(
            'External processing is temporarily unavailable; the import remains queued.',
            $job->error_message,
        );
        $this->assertDatabaseCount('genai_mcp_requests', 0);
        $this->assertSame([
            'job_id' => $job->id,
            'exception' => RuntimeException::class,
            'deferred' => true,
        ], $diagnostics->context['ParseImportJob: external enqueue deferred to recovery'] ?? null);
    }

    public function test_request_pruned_under_a_linked_attempt_defers_the_import_to_pending(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $prunedId = (string) $job->refresh()->mcp_request_id;

        // Pruned after this run captured its link and before enqueue() reads
        // the job: the third read of the row in handle() - the lookup, the
        // re-read after the dispatch claim, then enqueue()'s own refresh. The
        // `nullOnDelete` foreign key clears the link, enqueue() resets it and
        // takes the fresh path, and the staged document is unreachable there.
        $reads = 0;
        GenAiImportJob::retrieved(function (GenAiImportJob $read) use (&$reads, $job, $prunedId): void {
            if ((int) $read->id !== (int) $job->id || ++$reads !== 3) {
                return;
            }
            McpRequest::query()->whereKey($prunedId)->delete();
            Storage::disk('s3')->delete($job->s3_path);
        });
        $diagnostics = $this->captureDiagnostics();

        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->mcp_request_id);
        $this->assertSame(
            'External processing is temporarily unavailable; the import remains queued.',
            $job->error_message,
        );
        // The linked recovery, not the never-linked one, made this decision.
        $this->assertSame([
            'job_id' => $job->id,
            'exception' => RuntimeException::class,
            'deferred' => true,
            'import_status' => 'pending',
            'request_status' => null,
        ], $diagnostics->context['ParseImportJob: external enqueue deferred to recovery'] ?? null);
    }

    /**
     * Run a callback inside the patient write-grant re-check and then let the
     * check succeed, so the enqueue carries on into the staged-document test
     * with the interleaved state already committed.
     */
    private function interleaveDuringWritableLookup(Closure $interleave): void
    {
        $access = new class extends PhrPatientAccessService
        {
            public ?Closure $interleave = null;

            public function writablePatient(int $patientId, int $userId): PhrPatient
            {
                if ($this->interleave !== null) {
                    ($this->interleave)();
                    $this->interleave = null;
                }

                return parent::writablePatient($patientId, $userId);
            }
        };
        $access->interleave = $interleave;
        $this->app->instance(PhrPatientAccessService::class, $access);
    }

    /**
     * Run an optional callback inside the patient write-grant re-check and
     * then fail that check with a transient database error: the narrowest
     * real seam on enqueue()'s link-reuse path.
     */
    private function failTransientlyDuringWritableLookup(?Closure $interleave = null): void
    {
        $test = $this;
        $access = new class($test) extends PhrPatientAccessService
        {
            public ?Closure $interleave = null;

            public function __construct(private readonly ExternalGenAiEnqueueLifecycleTest $test) {}

            public function writablePatient(int $patientId, int $userId): PhrPatient
            {
                if ($this->interleave !== null) {
                    ($this->interleave)();
                    $this->interleave = null;
                }

                throw $this->test->transientQueryException();
            }
        };
        $access->interleave = $interleave;
        $this->app->instance(PhrPatientAccessService::class, $access);
    }

    public function transientQueryException(): QueryException
    {
        return new QueryException(
            'mysql',
            'select 1',
            [],
            new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'),
        );
    }

    /**
     * Record every log message's context by message, for exact assertions on
     * what a diagnostic says.
     */
    private function captureDiagnostics(): object
    {
        $diagnostics = new class
        {
            /** @var array<string, array<string, mixed>> */
            public array $context = [];
        };
        Log::listen(function (MessageLogged $entry) use ($diagnostics): void {
            $diagnostics->context[$entry->message] = $entry->context;
        });

        return $diagnostics;
    }

    /** Make the log destination throw for one event, and only that one. */
    private function throwWhenLogged(string $message): void
    {
        Log::listen(function (MessageLogged $entry) use ($message): void {
            if ($entry->message === $message) {
                throw new RuntimeException('The log destination is unavailable.');
            }
        });
    }

    /**
     * Point SafeLog's `error_log()` fallback at a file this test can read.
     * PHPUnit resets the `error_log` directive for every test method, so this
     * has to run inside the test body.
     */
    private function redirectErrorLog(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'genai-safelog-');
        $this->assertIsString($file);
        $this->errorLogFiles[] = $file;
        ini_set('error_log', $file);

        return $file;
    }

    /** @return array<string, mixed>|null */
    private function requestRow(string $requestId): ?array
    {
        $row = DB::table('genai_mcp_requests')->where('id', $requestId)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, mixed>|null */
    private function jobRow(int $jobId): ?array
    {
        $row = DB::table('genai_import_jobs')->where('id', $jobId)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Every queue state a request can be in when enqueue() reads it back,
     * paired with the package status and lease liveness the map keys on.
     *
     * @return array<string, array{array<string, mixed>, McpRequestStatus, bool}>
     */
    private function queueStates(): array
    {
        return [
            'queued' => [['status' => McpRequestStatus::Pending->value], McpRequestStatus::Pending, false],
            'leased with a live lease' => [
                ['status' => McpRequestStatus::Leased->value, 'lease_expires_at' => now()->addMinutes(5)],
                McpRequestStatus::Leased,
                true,
            ],
            'leased with an expired lease' => [
                ['status' => McpRequestStatus::Leased->value, 'lease_expires_at' => now()->subMinutes(5)],
                McpRequestStatus::Leased,
                false,
            ],
            'leased with no recorded lease expiry' => [
                ['status' => McpRequestStatus::Leased->value, 'lease_expires_at' => null],
                McpRequestStatus::Leased,
                false,
            ],
            'expired' => [['status' => McpRequestStatus::Expired->value], McpRequestStatus::Expired, false],
            'completed' => [['status' => McpRequestStatus::Completed->value], McpRequestStatus::Completed, false],
            'failed' => [['status' => McpRequestStatus::Failed->value], McpRequestStatus::Failed, false],
            'cancelled' => [['status' => McpRequestStatus::Cancelled->value], McpRequestStatus::Cancelled, false],
        ];
    }

    /**
     * Force the state the next request the package creates is read back in,
     * from inside the queue service's own insert.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function forceCreatedRequestState(array $attributes): void
    {
        $forced = false;
        McpRequest::created(function (McpRequest $created) use (&$forced, $attributes): void {
            if ($forced) {
                return;
            }
            $forced = true;
            McpRequest::query()->whereKey($created->id)->update($attributes);
        });
    }

    /**
     * The same window as above with no successor in it: the request is pruned
     * after this call read the link, so the row still carries the link the
     * reset compares against.
     */
    private function vanishDuringTheJobRead(GenAiImportJob $job, string $vanishingId): void
    {
        $vanished = false;
        GenAiImportJob::retrieved(function (GenAiImportJob $read) use (&$vanished, $job, $vanishingId): void {
            if ($vanished || (int) $read->id !== (int) $job->id) {
                return;
            }
            $vanished = true;
            McpRequest::query()->whereKey($vanishingId)->delete();
        });
    }

    /**
     * Force the #150 interleaving deterministically: the whole successor story
     * lands inside the job read at the top of enqueue(), between the read that
     * observes the link and the reset that clears it.
     *
     * The request is pruned first - the foreign key is `nullOnDelete`, so that
     * is also how the row's own link goes away - and a successor enqueue then
     * creates its own request and wins the link compare-and-swap. The caller's
     * instance keeps the attributes it was hydrated with, which is exactly the
     * stale view the unconditional reset used to write from.
     */
    private function vanishAndRelinkDuringTheJobRead(
        GenAiImportJob $job,
        string $vanishingId,
        string $successorId,
    ): void {
        $raced = false;
        GenAiImportJob::retrieved(function (GenAiImportJob $read) use (&$raced, $job, $vanishingId, $successorId): void {
            if ($raced || (int) $read->id !== (int) $job->id) {
                return;
            }
            $raced = true;
            McpRequest::query()->whereKey($vanishingId)->delete();
            GenAiImportJob::query()->whereKey($job->id)->update(['mcp_request_id' => $successorId]);
        });
    }

    /** @return list<string> */
    private function phpSourceFiles(string $directory): array
    {
        $files = [];
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Lines where a PHR status literal is produced - assigned, returned, or
     * placed on the value side of `=>`, `?` or `:` - inside a statement that
     * also names McpRequestStatus. Comments are ignored, and a literal that is
     * only compared against (`in_array($job->status, ['parsed', 'imported'])`)
     * is not a derivation and does not count.
     *
     * @param  list<string>  $phrStatuses
     * @return list<int>
     */
    private function phrStatusesProducedBesidePackageStatus(string $source, array $phrStatuses): array
    {
        $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $boundaries = [';', '{', '}'];
        $producers = ['=', '?', ':', T_DOUBLE_ARROW, T_RETURN];
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

        $lines = [];
        $candidates = [];
        $namesPackageStatus = false;
        $previous = null;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], $ignored, true)) {
                continue;
            }
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($id, $boundaries, true)) {
                if ($namesPackageStatus) {
                    $lines = [...$lines, ...$candidates];
                }
                $candidates = [];
                $namesPackageStatus = false;
                $previous = null;

                continue;
            }
            if (in_array($id, $names, true) && str_contains($text, 'McpRequestStatus')) {
                $namesPackageStatus = true;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING
                && in_array(trim($text, "'\""), $phrStatuses, true)
                && $previous !== null
                && in_array($previous, $producers, true)) {
                $candidates[] = is_array($token) ? $token[2] : 0;
            }
            $previous = $id;
        }
        if ($namesPackageStatus) {
            $lines = [...$lines, ...$candidates];
        }

        return $lines;
    }

    /**
     * Force the #114 interleaving deterministically: the callback runs inside
     * the patient-grant re-check, between the authorization this enqueue relies
     * on and the compare-and-swap that terminalizes the job.
     */
    private function interleaveDuringAuthorization(Closure $interleave): void
    {
        $access = new class extends PhrPatientAccessService
        {
            public ?Closure $interleave = null;

            public function writablePatient(int $patientId, int $userId): PhrPatient
            {
                if ($this->interleave !== null) {
                    ($this->interleave)();
                    $this->interleave = null;
                }

                throw new AuthorizationException('The patient write grant was revoked.');
            }
        };
        $access->interleave = $interleave;
        $this->app->instance(PhrPatientAccessService::class, $access);
    }

    /**
     * Make the very next cancellation write for this request fail with a
     * transient database error, and let every later attempt through.
     */
    private function failTheFirstCancellationOf(string $requestId): void
    {
        $attempts = 0;
        McpRequest::updating(function (McpRequest $model) use ($requestId, &$attempts): void {
            if ((string) $model->id !== $requestId || $model->status !== McpRequestStatus::Cancelled) {
                return;
            }
            $attempts++;
            if ($attempts > 1) {
                return;
            }

            throw new QueryException(
                'mysql',
                'update `genai_mcp_requests` set `status` = ? where `id` = ?',
                [McpRequestStatus::Cancelled->value, $requestId],
                new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'),
            );
        });
    }

    /**
     * Force the #118 interleaving deterministically: the callback runs inside
     * the queue service's own insert, between creating the request and the
     * compare-and-swap that links it.
     *
     * @param  callable(McpRequest): void  $interleave
     * @return string the id of the request that lost the race
     */
    private function enqueueLosingTheLinkRace(GenAiImportJob $job, callable $interleave): string
    {
        // Free the link and move past the current idempotency key so a second
        // enqueue creates its own request.
        $job->forceFill([
            'mcp_request_id' => null,
            'mcp_generation' => $job->mcp_generation + 1,
            'status' => 'pending',
        ])->save();

        $lostId = null;
        McpRequest::created(function (McpRequest $created) use (&$lostId, $interleave): void {
            if ($lostId !== null) {
                return;
            }
            $lostId = (string) $created->id;
            $interleave($created);
        });

        try {
            app(PhrExternalGenAiRequestService::class)->enqueue($job->refresh());
            $this->fail('The losing enqueue reported success.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The import execution mode changed while work was queued.', $exception->getMessage());
        }
        $this->assertIsString($lostId);

        return $lostId;
    }

    private function requestStatus(string $requestId): ?string
    {
        $status = GenAiImportJob::query()->getConnection()
            ->table('genai_mcp_requests')
            ->where('id', $requestId)
            ->value('status');

        return $status === null ? null : (string) $status;
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
            'display_name' => 'Synthetic Lifecycle Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $owner ? PhrPatientUserAccess::LEVEL_OWNER : PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $patientOwner->id,
            'granted_at' => now(),
        ]);
        $bytes = '%PDF-1.4 synthetic enqueue lifecycle';
        $path = 'genai-import/'.$user->id.'/lifecycle/source.pdf';
        Storage::disk('s3')->put($path, $bytes);
        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patientOwner->id,
            'uploaded_by_user_id' => $user->id,
            'title' => 'Synthetic lifecycle document',
            'document_type' => 'lab_report',
            'original_filename' => 'synthetic-private-name.pdf',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => 'patients/'.$patient->id.'/documents/lifecycle.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'file_hash' => hash('sha256', $bytes),
            'source' => 'manual_upload',
        ]);
        $job = GenAiImportJob::query()->create([
            'user_id' => $user->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', $bytes),
            'original_filename' => 'synthetic-private-name.pdf',
            's3_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => strlen($bytes),
            'context_json' => json_encode(['patient_id' => $patient->id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return [$user, $patient, $document, $job];
    }
}
