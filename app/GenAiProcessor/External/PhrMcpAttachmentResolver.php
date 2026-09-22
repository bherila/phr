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
        // every check above. Recompute the SHA-256 of the live bytes with a
        // streaming hash (hash_init/hash_update_stream/hash_final) so a mutated
        // object never gets streamed under stale integrity metadata, without ever
        // holding the full (up to 100 MiB) object in PHP memory.
        if (! $this->liveBytesMatchRecordedHash($job)) {
            $this->terminalizeIntegrityFailure($request);
            throw new NotFoundHttpException;
        }

        // Ordering/TOCTOU note: verification above already consumed one full read of
        // the S3 object to hash it, so that same stream cannot be handed back to the
        // caller. We re-open a second, independent read here instead of buffering
        // the verified bytes in memory (which would defeat the point of streaming
        // verification for up to 100 MiB objects). This leaves a window, between the
        // verifying read finishing and this second read starting, in which the
        // object could theoretically be mutated again and stream unverified bytes.
        // That window is not closed by anything below. We accept it here because: it
        // requires an attacker able to mutate this private S3 object in the first
        // place (the same precondition the whole check defends against), the window
        // is a single request's worth of wall-clock time rather than an unbounded
        // lease lifetime, and closing it fully would require reading a specific S3
        // object *version* for both operations, which this disk/bucket does not
        // currently guarantee is enabled. If the bucket adopts versioning, pinning
        // both reads to one version id would remove this window entirely.
        $stream = Storage::disk('s3')->readStream($job->s3_path);
        if (! is_resource($stream)) {
            throw new NotFoundHttpException;
        }

        return $stream;
    }

    private function liveBytesMatchRecordedHash(GenAiImportJob $job): bool
    {
        $stream = Storage::disk('s3')->readStream($job->s3_path);
        if (! is_resource($stream)) {
            return false;
        }
        try {
            $hashContext = hash_init('sha256');
            hash_update_stream($hashContext, $stream);
            $liveHash = hash_final($hashContext);
        } finally {
            fclose($stream);
        }

        return hash_equals(strtolower($job->file_hash), $liveHash);
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
