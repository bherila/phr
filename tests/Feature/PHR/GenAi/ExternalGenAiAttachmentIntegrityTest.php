<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\External\HashVerifyingReadStream;
use App\GenAiProcessor\External\PhrMcpAttachmentResolver;
use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Regression coverage for issue #117: PhrMcpAttachmentResolver::readStream must
 * recompute the SHA-256 of the live S3 bytes (not just compare stored metadata)
 * before streaming a host-reference attachment, fail closed on a same-size
 * mutation, and terminalize the associated request/job instead of leaving it to
 * loop forever through claim/lease recovery.
 */
final class ExternalGenAiAttachmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake();
    }

    public function test_same_size_mutation_of_the_s3_object_fails_closed_and_terminalizes_the_request(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $requestId = $job->mcp_request_id;
        $this->assertIsString($requestId);
        $mailboxId = (string) $job->getConnection()->table('genai_mcp_requests')->where('id', $requestId)->value('mailbox_id');
        $context = $this->context($job->user, $mailboxId);
        $queue = app(McpQueueService::class);

        $claim = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($claim);
        $this->assertSame('processing', $job->refresh()->status);

        $original = $this->documentBytes();
        $this->assertSame(strlen($original), strlen($this->mutatedSameSizeBytes()));
        Storage::disk('s3')->put($job->s3_path, $this->mutatedSameSizeBytes());
        $this->assertSame(
            strlen($original),
            Storage::disk('s3')->size($job->s3_path),
            'The mutation must preserve byte length to exercise the same-size mutation case.',
        );

        $attachment = McpAttachment::query()->where('request_id', $requestId)->sole();
        $resolver = app(PhrMcpAttachmentResolver::class);

        // The object is read once and hashed as it streams, so a mismatch is only
        // detectable at EOF: readStream() itself succeeds, and the verdict lands
        // while the caller drains the stream.
        $stream = $resolver->readStream($attachment, $context);
        $this->assertIsResource($stream);
        $delivered = stream_get_contents($stream);
        fclose($stream);
        $this->assertNotSame($this->mutatedSameSizeBytes(), $delivered, 'The complete mutated object must never be delivered.');
        $this->assertLessThan(strlen($original), strlen((string) $delivered));

        // The request must be terminalized immediately so it cannot be reclaimed and
        // hashed again forever; it should not merely wait out its lease.
        $mcpRequest = McpRequest::query()->findOrFail($requestId);
        $this->assertSame(McpRequestStatus::Failed, $mcpRequest->status);
        $this->assertSame('attachment_integrity_failed', $mcpRequest->error['code'] ?? null);
        $this->assertDatabaseHas('genai_mcp_deliveries', [
            'request_id' => $requestId,
            'type' => 'failed',
        ]);

        // A failed, terminal request can never be claimed (and therefore its
        // attachment never re-verified-and-streamed) again.
        $this->assertNull($queue->claim($context, 'phr-imports'));

        // Draining the delivery applies the same terminal genai_import_jobs status
        // that every other external failure path in this subsystem produces.
        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->error_message);
    }

    public function test_unchanged_bytes_still_stream_successfully(): void
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $requestId = $job->mcp_request_id;
        $this->assertIsString($requestId);
        $mailboxId = (string) $job->getConnection()->table('genai_mcp_requests')->where('id', $requestId)->value('mailbox_id');
        $context = $this->context($job->user, $mailboxId);
        $queue = app(McpQueueService::class);

        $claim = $queue->claim($context, 'phr-imports');
        $this->assertIsArray($claim);
        $this->assertSame('processing', $job->refresh()->status);

        $attachment = McpAttachment::query()->where('request_id', $requestId)->sole();
        $resolver = app(PhrMcpAttachmentResolver::class);

        $stream = $resolver->readStream($attachment, $context);
        $this->assertIsResource($stream);
        $this->assertSame($this->documentBytes(), stream_get_contents($stream));
        fclose($stream);

        // Verification must not itself disturb the request/job state.
        $this->assertSame(McpRequestStatus::Leased, McpRequest::query()->findOrFail($requestId)->status);
        $this->assertSame('processing', $job->refresh()->status);
        $this->assertDatabaseMissing('genai_mcp_deliveries', ['request_id' => $requestId, 'type' => 'failed']);
    }

    public function test_an_attachment_fetch_reads_the_object_bytes_exactly_once(): void
    {
        [$context, $job, $requestId, $attachment] = $this->claimedExternalJob();
        $reads = $this->swapS3ReadStream($this->documentBytes());

        $stream = app(PhrMcpAttachmentResolver::class)->readStream($attachment, $context);
        $this->assertSame($this->documentBytes(), stream_get_contents($stream));
        fclose($stream);

        $this->assertSame(1, $reads->count);
        $this->assertStillRetryable($requestId, $job);
    }

    public function test_a_multi_chunk_mutation_detected_only_at_eof_terminalizes_and_withholds_the_final_chunk(): void
    {
        [$context, $job, $requestId, $attachment] = $this->claimedExternalJob();
        $original = '%PDF-1.4 '.str_repeat('a', 200_000);
        $this->recordAttachmentBytes($job, $attachment, $original);
        // Only the very last byte differs, so no prefix of the stream can reveal
        // the mutation: the verdict is only available once the final byte is read.
        $mutated = substr_replace($original, 'b', -1, 1);
        Storage::disk('s3')->put($job->s3_path, $mutated);

        $stream = app(PhrMcpAttachmentResolver::class)->readStream($attachment, $context);
        $delivered = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            // Nothing is decided while the stream is still short of EOF.
            if (strlen($delivered) + strlen($chunk) < strlen($original)) {
                $this->assertSame(McpRequestStatus::Leased, McpRequest::query()->findOrFail($requestId)->status);
            }
            $delivered .= $chunk;
        }
        fclose($stream);

        $this->assertStringStartsWith($delivered, $mutated);
        $this->assertLessThan(strlen($mutated), strlen($delivered), 'The chunk that completed the mismatching hash must be withheld.');
        $this->assertSame(McpRequestStatus::Failed, McpRequest::query()->findOrFail($requestId)->status);
        $this->assertDatabaseHas('genai_mcp_deliveries', ['request_id' => $requestId, 'type' => 'failed']);
    }

    public function test_a_mutated_stream_closed_before_eof_stays_retryable(): void
    {
        [$context, $job, $requestId, $attachment] = $this->claimedExternalJob();
        // Larger than PHP's 8 KiB stream read buffer, so the first read cannot
        // already reach EOF.
        $original = '%PDF-1.4 '.str_repeat('a', 200_000);
        $this->recordAttachmentBytes($job, $attachment, $original);
        $mutated = substr_replace($original, 'b', -1, 1);
        Storage::disk('s3')->put($job->s3_path, $mutated);

        // A client that disconnects part way never reaches the verdict; an
        // unfinished read is inconclusive, not proof of mutation.
        $stream = app(PhrMcpAttachmentResolver::class)->readStream($attachment, $context);
        $this->assertSame(substr($mutated, 0, 4), fread($stream, 4));
        fclose($stream);

        $this->assertStillRetryable($requestId, $job);
    }

    public function test_an_object_that_grew_after_the_size_check_delivers_only_the_verified_bytes(): void
    {
        [$context, $job, $requestId, $attachment] = $this->claimedExternalJob();
        $this->swapS3ReadStream($this->documentBytes().'appended after the size check');

        $stream = app(PhrMcpAttachmentResolver::class)->readStream($attachment, $context);
        $this->assertSame($this->documentBytes(), stream_get_contents($stream));
        fclose($stream);

        $this->assertStillRetryable($requestId, $job);
    }

    public function test_a_failing_mismatch_handler_is_reported_without_escaping_the_read(): void
    {
        $bytes = str_repeat('z', 20_000);
        $inner = fopen('php://memory', 'r+');
        fwrite($inner, $bytes);
        rewind($inner);
        $exceptions = Exceptions::fake();

        $stream = HashVerifyingReadStream::wrap($inner, hash('sha256', 'something else'), strlen($bytes), static function (): void {
            throw new RuntimeException('synthetic terminalization failure');
        });
        $delivered = stream_get_contents($stream);
        fclose($stream);

        $this->assertLessThan(strlen($bytes), strlen((string) $delivered));
        $exceptions->assertReported(RuntimeException::class);
    }

    public function test_a_transient_storage_read_failure_stays_retryable(): void
    {
        [$context, $job, $requestId, $attachment] = $this->claimedExternalJob();

        // Every disk sets throw => false, so an S3 or network failure returns a
        // false stream rather than raising. That is not evidence of mutation.
        $this->swapS3ReadStream(false);

        try {
            app(PhrMcpAttachmentResolver::class)->readStream($attachment, $context);
            $this->fail('An unreadable attachment must not be streamed.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $this->assertStillRetryable($requestId, $job);
    }

    public function test_a_truncated_read_stays_retryable(): void
    {
        [$context, $job, $requestId, $attachment] = $this->claimedExternalJob();

        // A transfer that breaks partway hashes only a prefix, which would look
        // exactly like a same-size mutation if the short read went undetected.
        $this->swapS3ReadStream(substr($this->documentBytes(), 0, 8));

        // The truncation is only visible once the transfer ends, so the caller
        // receives the short prefix (a Content-Length mismatch for an HTTP client)
        // and the request is left for lease recovery rather than terminalized.
        $stream = app(PhrMcpAttachmentResolver::class)->readStream($attachment, $context);
        $this->assertSame(substr($this->documentBytes(), 0, 8), stream_get_contents($stream));
        fclose($stream);

        $this->assertStillRetryable($requestId, $job);
    }

    /**
     * A retryable read failure must leave the lease/retry recovery that already
     * handles transient storage outages completely intact.
     */
    private function assertStillRetryable(string $requestId, GenAiImportJob $job): void
    {
        $this->assertSame(McpRequestStatus::Leased, McpRequest::query()->findOrFail($requestId)->status);
        $this->assertDatabaseMissing('genai_mcp_deliveries', ['request_id' => $requestId, 'type' => 'failed']);
        $this->assertSame('processing', $job->refresh()->status);
        $this->assertNull($job->error_message);
    }

    private function swapS3ReadStream(false|string $result): object
    {
        $reads = new class
        {
            public int $count = 0;
        };
        $real = Storage::disk('s3');
        $mock = Mockery::mock($real);
        $mock->shouldReceive('readStream')->andReturnUsing(static function () use ($result, $reads) {
            $reads->count++;
            if ($result === false) {
                return false;
            }
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $result);
            rewind($stream);

            return $stream;
        });
        $mock->shouldReceive('exists')->andReturnUsing(static fn (string $path): bool => $real->exists($path));
        $mock->shouldReceive('size')->andReturnUsing(static fn (string $path): int => $real->size($path));
        Storage::set('s3', $mock);

        return $reads;
    }

    private function recordAttachmentBytes(GenAiImportJob $job, McpAttachment $attachment, string $bytes): void
    {
        Storage::disk('s3')->put($job->s3_path, $bytes);
        $job->forceFill(['file_hash' => hash('sha256', $bytes), 'file_size_bytes' => strlen($bytes)])->save();
        $attachment->forceFill(['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)])->save();
    }

    /** @return array{ExecutionContext, GenAiImportJob, string, McpAttachment} */
    private function claimedExternalJob(): array
    {
        [, , , $job] = $this->externalJob();
        (new ParseImportJob($job->id))->handle();
        $job->refresh();
        $requestId = $job->mcp_request_id;
        $this->assertIsString($requestId);
        $mailboxId = (string) $job->getConnection()->table('genai_mcp_requests')->where('id', $requestId)->value('mailbox_id');
        $context = $this->context($job->user, $mailboxId);

        $this->assertIsArray(app(McpQueueService::class)->claim($context, 'phr-imports'));
        $this->assertSame('processing', $job->refresh()->status);

        return [$context, $job, $requestId, McpAttachment::query()->where('request_id', $requestId)->sole()];
    }

    /** @return array{User, PhrPatient, PhrDocument, GenAiImportJob} */
    private function externalJob(): array
    {
        $user = User::factory()->create([
            'user_role' => 'user',
            'genai_execution_mode' => GenAiImportJob::EXECUTION_EXTERNAL,
        ]);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $user->id,
            'display_name' => 'Synthetic Integrity Patient',
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
            'title' => 'Synthetic integrity document',
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
        return '%PDF-1.4 synthetic integrity import';
    }

    /** Same length as documentBytes(), different content: a same-size mutation. */
    private function mutatedSameSizeBytes(): string
    {
        $original = $this->documentBytes();

        return substr_replace($original, 'X', -1, 1);
    }
}
