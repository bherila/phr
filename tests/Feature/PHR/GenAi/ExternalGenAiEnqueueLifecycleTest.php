<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\PhrExternalEnqueueUnauthorized;
use App\GenAiProcessor\Services\PhrExternalGenAiRequestService;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
