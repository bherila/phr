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
use Illuminate\Support\Facades\Bus;
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

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
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
