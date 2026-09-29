<?php

namespace App\GenAiProcessor\External;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Contracts\AttachmentResolver;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PhrMcpAttachmentResolver implements AttachmentResolver
{
    public function __construct(private PhrMcpMailboxAccessResolver $access) {}

    /** @return resource */
    public function readStream(McpAttachment $attachment, ExecutionContext $context)
    {
        $request = McpRequest::query()->with('mailbox')->find($attachment->request_id);
        if (! $request instanceof McpRequest
            || ! $this->access->authorize($context, $request->mailbox, AgentApiScopes::GENAI_WORK, $request)
            || preg_match('/^phr-genai-job:(\d+)$/D', (string) $attachment->host_reference, $matches) !== 1) {
            throw new NotFoundHttpException;
        }
        $job = GenAiImportJob::query()
            ->whereKey((int) $matches[1])
            ->where('mcp_request_id', $request->id)
            ->first();
        if (! $job instanceof GenAiImportJob
            || ! hash_equals(strtolower($job->file_hash), strtolower($attachment->sha256))
            || (int) $job->file_size_bytes !== (int) $attachment->size
            || ! Storage::disk('s3')->exists($job->s3_path)
            || (int) Storage::disk('s3')->size($job->s3_path) !== (int) $attachment->size) {
            throw new NotFoundHttpException;
        }

        // The checks above only compare database-recorded metadata against other
        // database-recorded metadata (job->file_hash vs attachment->sha256, both
        // written from the same value at enqueue time) plus the live S3 byte
        // *count*. None of that proves the S3 object's bytes are still what was
        // hashed at enqueue time: a same-size mutation of the private object passes
        // every check above.
        //
        // So the object is read exactly once and hashed as the caller consumes it
        // (issue #147). The bytes hashed are exactly the bytes delivered, which
        // removes the window a separate verifying read would leave, and the object
        // is never held in PHP memory. The trade-off is that the verdict arrives at
        // EOF, after most of the body has been sent. The final chunk is withheld on
        // a mismatch, so the complete mutated object is never delivered, and the
        // request is terminalized so no completion derived from it can be accepted.
        //
        // Only a *completed* read that disagrees with the recorded hash proves the
        // object was mutated. Every disk in config/filesystems.php sets
        // throw => false, so an ordinary S3/network failure surfaces as a false
        // stream or a short read rather than an exception. Those, and a client
        // that disconnects before EOF, are inconclusive and stay retryable through
        // the existing lease recovery rather than becoming a permanent failure.
        $stream = Storage::disk('s3')->readStream($job->s3_path);
        if (! is_resource($stream)) {
            throw new NotFoundHttpException;
        }

        return HashVerifyingReadStream::wrap(
            $stream,
            (string) $job->file_hash,
            (int) $attachment->size,
            fn () => $this->terminalizeIntegrityFailure($request),
        );
    }

    /**
     * Fail the MCP request closed, the same way the package's own lease-exhaustion
     * path (McpQueueService::failExhaustedLeases) terminalizes stuck work: mark the
     * request Failed directly (so it can never be reclaimed and re-leased again) and
     * record a 'failed' delivery so genai:mcp:deliver applies the terminal
     * genai_import_jobs status through PhrMcpCompletionDelivery's existing
     * ['pending','processing'] -> 'failed' transition, exactly as it does for any
     * other external failure delivery.
     */
    private function terminalizeIntegrityFailure(McpRequest $request): void
    {
        DB::connection()->transaction(function () use ($request): void {
            $locked = McpRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $locked instanceof McpRequest || in_array($locked->status, [
                McpRequestStatus::Completed,
                McpRequestStatus::Failed,
                McpRequestStatus::Expired,
                McpRequestStatus::Cancelled,
            ], true)) {
                return;
            }
            $error = [
                'code' => 'attachment_integrity_failed',
                'message' => 'The source attachment no longer matches its recorded integrity hash.',
            ];
            $locked->forceFill([
                'status' => McpRequestStatus::Failed,
                'failed_at' => now(),
                'error' => $error,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'lease_principal' => null,
            ])->save();
            McpDelivery::query()->firstOrCreate(
                ['request_id' => $locked->id, 'type' => 'failed'],
                ['payload' => ['error' => $error], 'available_at' => now()],
            );
        });
    }
}
