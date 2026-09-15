<?php

namespace App\GenAiProcessor\External;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Contracts\AttachmentResolver;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
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
        $stream = Storage::disk('s3')->readStream($job->s3_path);
        if (! is_resource($stream)) {
            throw new NotFoundHttpException;
        }

        return $stream;
    }
}
