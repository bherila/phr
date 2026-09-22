<?php

namespace App\GenAiProcessor\Jobs;

use App\GenAiProcessor\Mail\GenAiJobCompleteMail;
use App\GenAiProcessor\Mail\GenAiJobDeferredMail;
use App\GenAiProcessor\Models\GenAiDailyQuota;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\PhrExternalEnqueueUnauthorized;
use App\GenAiProcessor\Services\PhrExternalGenAiRequestService;
use App\GenAiProcessor\Services\PhrGenAiRequestPreparationService;
use App\GenAiProcessor\Services\PhrImportExecutionModeChanged;
use App\GenAiProcessor\Services\PhrImportProposalApplicationService;
use App\GenAiProcessor\Support\PhrExternalImportStatusMap;
use App\Models\User;
use App\Services\GenAiFileHelper;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use App\Support\Logging\SafeLog;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use HelgeSverre\Toon\Toon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * PHR's own minimal GenAI import-job queue.
 *
 * This job implements only the plain TOON/JSON text-output path and PHR's own
 * result-splitting logic, directly against the public `bherila/genai-laravel`
 * client. It intentionally has no deterministic-parser tier, no tax-document
 * coupling, and no cross-account matching.
 */
class ParseImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    private const EXTERNAL_DEFERRED_MESSAGE = 'External processing is temporarily unavailable; the import remains queued.';

    public function __construct(
        public int $jobId
    ) {
        $this->onQueue('genai-imports');
    }

    public function handle(): void
    {
        $job = GenAiImportJob::find($this->jobId);

        if (! $job) {
            Log::info('ParseImportJob: skipping stale dispatch', ['job_id' => $this->jobId]);

            return;
        }

        // A recovery poll may redispatch a pending row whose original queue
        // message is merely delayed. Claim the row before doing any provider
        // work so only one of those messages can process it.
        $claimed = GenAiImportJob::query()
            ->whereKey($job->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'processing',
                'scheduled_for' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            Log::info('ParseImportJob: skipping stale dispatch', ['job_id' => $this->jobId]);

            return;
        }

        $job->refresh();

        // The execution generation this run belongs to. Any later write that
        // hands work to the external queue must be scoped to it, because an
        // execution-mode change bumps the generation and dispatches a successor
        // run that may finish first.
        $apiGeneration = (int) $job->mcp_generation;

        if (! PhrStructuredDataImporter::isPhrJobType($job->job_type)) {
            $job->markFailed('Unsupported job type: '.$job->job_type);

            return;
        }

        $user = $job->user;
        if (! $user) {
            $job->markFailed('User not found');

            return;
        }

        $executionMode = $user->genAiExecutionMode();
        if ($job->execution_mode !== $executionMode) {
            $job->update(['execution_mode' => $executionMode]);
        }
        if ($executionMode === GenAiImportJob::EXECUTION_EXTERNAL) {
            $this->queueExternally($job);

            return;
        }

        $activeConfig = $user->activeAiConfiguration();
        if ($activeConfig && $activeConfig->isExpired()) {
            $job->markFailed('Your AI configuration "'.$activeConfig->name.'" has expired. Please update it in Settings.');

            return;
        }
        if ($activeConfig && $activeConfig->hasInvalidApiKey()) {
            $job->markFailed('Your AI configuration "'.$activeConfig->name.'" has an invalid API key. Please update it in Settings.');

            return;
        }

        $client = $user->resolvedAiClient();
        if (! $client) {
            $job->markFailed('No AI configuration found. Please add one in Settings.');

            return;
        }

        $fileStream = null;

        try {
            $fileStream = Storage::disk('s3')->readStream($job->s3_path);
            if (! $fileStream) {
                $job->markFailed('File not found in storage');

                return;
            }

            $fileSize = (int) (Storage::disk('s3')->size($job->s3_path) ?: 0);
            if ($fileSize > 0 && ! GenAiFileHelper::withinSizeLimit($client, $fileSize, $job->mime_type ?? 'application/pdf')) {
                $job->markFailed('File exceeds the size limit for the configured AI provider.');

                return;
            }

            $prompt = app(PhrGenAiRequestPreparationService::class)->prepare($job)->prompt;

            if (! $this->claimQuota($user->id, $user, $job->id)) {
                $job->markQueuedTomorrow();
                Log::info('ParseImportJob: quota exhausted, deferred', ['job_id' => $job->id]);

                try {
                    Mail::to($user->email)->send(new GenAiJobDeferredMail($job));
                } catch (\Throwable $mailEx) {
                    Log::warning('Failed to send deferred mail', [
                        'job_id' => $job->id,
                        'exception' => $mailEx::class,
                    ]);
                }

                return;
            }

            // From here on this run is racing any successor generation the
            // execution-mode transaction may have dispatched, so every write
            // is scoped to the generation that claimed the row.
            $this->writeApiGeneration($job, $apiGeneration, [
                'ai_configuration_id' => $activeConfig?->id,
                'ai_provider' => $client->provider(),
                'ai_model' => $client->model(),
            ]);

            $response = GenAiFileHelper::send(
                $client,
                $fileStream,
                $job->mime_type ?? 'application/pdf',
                'genai-import-'.time(),
                $prompt,
            );

            $rawResponse = json_encode($response);
            $inputTokens = null;
            $outputTokens = null;
            [$inputTokens, $outputTokens] = $this->extractTokenUsage(is_array($response) ? $response : []);

            $jobUpdates = [];
            if ($rawResponse !== false) {
                $jobUpdates['raw_response'] = $rawResponse;
            }
            if ($inputTokens !== null) {
                $jobUpdates['input_tokens'] = $inputTokens;
            }
            if ($outputTokens !== null) {
                $jobUpdates['output_tokens'] = $outputTokens;
            }
            if (! empty($jobUpdates)) {
                $this->writeApiGeneration($job, $apiGeneration, $jobUpdates);
            }

            $text = $this->extractResponseText(is_array($response) ? $response : []);
            $data = $this->decodeStructuredText($text);

            if ($data === null) {
                $this->failApiGeneration($job, $apiGeneration, 'AI returned text, but it was not valid TOON or JSON.');

                return;
            }

            $job->refresh();
            $user->refresh();
            if ($user->genAiExecutionMode() === GenAiImportJob::EXECUTION_EXTERNAL
                || $job->execution_mode === GenAiImportJob::EXECUTION_EXTERNAL) {
                $this->handOffToExternalQueue($job, $apiGeneration);

                return;
            }

            try {
                app(PhrImportProposalApplicationService::class)->applyApiResult($job->id, $data);
            } catch (PhrImportExecutionModeChanged) {
                // The preference transaction won after this API request began.
                // Discard its output and hand the still-pending work to the
                // selected external queue; never let the stale API result win.
                $this->handOffToExternalQueue($job, $apiGeneration);

                return;
            }
            $job->refresh();

            Log::info('ParseImportJob: success', [
                'job_id' => $job->id,
                'result_count' => $job->results()->count(),
            ]);

            try {
                Mail::to($user->email)->send(new GenAiJobCompleteMail($job));
            } catch (\Throwable $mailEx) {
                Log::warning('Failed to send completion mail', [
                    'job_id' => $job->id,
                    'exception' => $mailEx::class,
                ]);
            }
        } catch (GenAiRateLimitException) {
            $this->failApiGeneration($job, $apiGeneration, 'API rate limit exceeded. Please wait and try again.');
        } catch (GenAiFatalException) {
            $this->writeApiGeneration($job, $apiGeneration, [
                'status' => 'failed',
                'error_message' => 'The configured AI provider rejected the import request.',
                'retry_count' => GenAiImportJob::MAX_RETRIES,
            ]);
        } catch (\Throwable $e) {
            Log::error('ParseImportJob: unexpected error', [
                'job_id' => $job->id,
                'exception' => $e::class,
            ]);
            $this->failApiGeneration($job, $apiGeneration, 'An unexpected import error occurred.');
        } finally {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    /**
     * Apply a write only this run's API execution generation may make.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeApiGeneration(GenAiImportJob $job, int $apiGeneration, array $values): bool
    {
        $applied = PhrExternalImportStatusMap::updateApiGeneration($job->id, $apiGeneration, $values);
        if ($applied) {
            $job->refresh();
        }

        return $applied;
    }

    /** Fail this run's API execution generation, charging it one PHR retry. */
    private function failApiGeneration(GenAiImportJob $job, int $apiGeneration, string $errorMessage): void
    {
        $this->writeApiGeneration($job, $apiGeneration, [
            'status' => 'failed',
            'error_message' => $errorMessage,
            'retry_count' => DB::raw('retry_count + 1'),
        ]);
    }

    /**
     * Hand this run's still-nonterminal API generation to the external queue.
     *
     * The write is conditional on the generation this run claimed, so a
     * successor external run that already delivered a completion keeps its
     * terminal status and its proposals. When this run has been superseded it
     * simply stops: the execution-mode transaction already dispatched the
     * successor that owns the row.
     */
    private function handOffToExternalQueue(GenAiImportJob $job, int $apiGeneration): void
    {
        if (! PhrExternalImportStatusMap::handOffApiGenerationToExternal($job->id, $apiGeneration)) {
            Log::info('ParseImportJob: stale API handoff was superseded', ['job_id' => $job->id]);

            return;
        }

        $job->refresh();
        $this->queueExternally($job);
    }

    /**
     * Hand a job to the external queue on behalf of one row revision.
     *
     * The revision - execution mode, generation and the link this attempt
     * started from - is captured before the enqueue, not read back after it.
     * Fencing only the service is not enough: a mode change, a retry or a
     * recovery redispatch can supersede this attempt while it is inside
     * enqueue(), and the failure path below then writes to whatever row it
     * finds. Predicated on external mode and a nonterminal status alone, that
     * write demotes a successor that is already `processing` back to `pending`
     * and annotates it with a failure belonging to the attempt it replaced.
     */
    private function queueExternally(GenAiImportJob $job): void
    {
        $ownedMode = (string) $job->execution_mode;
        $ownedGeneration = (int) $job->mcp_generation;
        $ownedLink = $job->mcp_request_id === null ? null : (string) $job->mcp_request_id;

        try {
            $request = app(PhrExternalGenAiRequestService::class)->enqueue($job);
            // The local claim at the top of handle() is only a dispatch lock.
            // Conform the row to the durable package request so a recovery
            // redispatch cannot leave an import "processing" with no live lease.
            PhrExternalImportStatusMap::reconcile($request);
            $job->refresh();
            // Re-read rather than reported from the snapshot enqueue() handed
            // back: reconcile() derives its target inside its own locked
            // transaction, and a client may have claimed the request since.
            $reconciled = McpRequest::query()->find($request->getKey());
            // enqueue() reconciles the import against its durable request
            // rather than forcing a queued outcome, and the map turns an
            // already-`expired` request straight into `failed`. Reporting
            // "queued for external processing" unconditionally would describe
            // an import that is finished - and finished unsuccessfully - as
            // in flight, which is exactly the diagnostic someone debugging a
            // stuck import would trust. Report what the row actually says.
            //
            // SafeLog, because this is written after the enqueue and its
            // reconciliation have already succeeded, inside the try whose
            // generic catch is business recovery. A throwing log destination
            // there would turn a finished success into a recovery write.
            SafeLog::info('ParseImportJob: external enqueue reconciled', [
                'job_id' => $job->id,
                'import_status' => $job->status,
                'request_status' => $reconciled?->status->value,
            ]);
        } catch (PhrExternalEnqueueUnauthorized) {
            // The enqueue path already failed the job and cancelled any
            // orphaned request. Leaving it pending here would restart the
            // recovery loop this terminal state exists to stop.
            //
            // SafeLog: the outcome is already decided, and a throwing log
            // destination would escape handle() - recording a finished job as
            // a failed queue job, reaching the API path's "unexpected error"
            // catch on a handoff, and making a synchronous dispatcher report a
            // failed dispatch.
            SafeLog::info('ParseImportJob: external import terminalized after authorization was lost', [
                'job_id' => $job->id,
            ]);
        } catch (\Throwable $exception) {
            if ($ownedLink !== null) {
                $this->recoverLinkedRevision($job, $ownedMode, $ownedGeneration, $ownedLink, $exception);

                return;
            }
            // No request owned this revision's work when the attempt started,
            // so there is no durable state to defer to: the import waits for
            // PHR's own pending recovery. The same compare-and-swap the
            // service makes, over the revision captured above: same mode, same
            // generation, still unlinked, still nonterminal. A superseded
            // attempt - including one whose own enqueue linked the row before
            // failing - matches no rows and leaves the row exactly as it
            // found it.
            $deferred = GenAiImportJob::query()
                ->whereKey($job->id)
                ->where('execution_mode', $ownedMode)
                ->where('mcp_generation', $ownedGeneration)
                ->whereNull('mcp_request_id')
                ->whereIn('status', PhrExternalImportStatusMap::NONTERMINAL_PHR_STATUSES)
                ->update([
                    'status' => 'pending',
                    'error_message' => self::EXTERNAL_DEFERRED_MESSAGE,
                    'updated_at' => now(),
                ]);
            // SafeLog for the same reason as above: the recovery write has
            // landed, and nothing about it should depend on the log.
            SafeLog::warning('ParseImportJob: external enqueue deferred to recovery', [
                'job_id' => $job->id,
                'exception' => $exception::class,
                // False means this attempt no longer owned the row, so the
                // deferral belongs to whoever does.
                'deferred' => $deferred === 1,
            ]);
        }
    }

    /**
     * Recover a failed enqueue whose revision was already linked to a request.
     *
     * The failure says nothing about the request: a transient error while
     * re-checking authorization for reuse can land while a client holds - or
     * has just gained - a live lease on it. Writing `pending` over that would
     * contradict the durable request the status map declares authoritative, so
     * the import is conformed to the request instead, under the request lock
     * and on the captured revision, by
     * {@see PhrExternalImportStatusMap::recoverLinkedRevision()}.
     *
     * If that recovery cannot read the request either - the database itself is
     * failing - nothing is known that would justify any status, so the import
     * is left exactly as it is. The recovery command's reconciliation pass
     * re-derives a linked external row from its request once the database is
     * back; a guessed status written now would only be something for it to
     * undo, and until then it reads exactly like a real one.
     */
    private function recoverLinkedRevision(
        GenAiImportJob $job,
        string $ownedMode,
        int $ownedGeneration,
        string $ownedLink,
        \Throwable $exception,
    ): void {
        try {
            $recovered = PhrExternalImportStatusMap::recoverLinkedRevision(
                $job->id,
                $ownedMode,
                $ownedGeneration,
                $ownedLink,
                self::EXTERNAL_DEFERRED_MESSAGE,
            );
        } catch (\Throwable $recoveryException) {
            SafeLog::warning('ParseImportJob: external enqueue recovery left the import unchanged', [
                'job_id' => $job->id,
                'exception' => $exception::class,
                'recovery_exception' => $recoveryException::class,
            ]);

            return;
        }

        SafeLog::warning('ParseImportJob: external enqueue deferred to recovery', [
            'job_id' => $job->id,
            'exception' => $exception::class,
            // False means this attempt no longer owned the row, or the request
            // is in a state whose PHR status another writer owns.
            'deferred' => $recovered['import_status'] !== null,
            'import_status' => $recovered['import_status'],
            'request_status' => $recovered['request_status'],
        ]);
    }

    /**
     * Atomically claim a quota slot for today (UTC) — a site-wide + per-user quota check.
     */
    private function claimQuota(int $userId, User $user, ?int $excludeJobId = null): bool
    {
        $siteLimit = (int) config('genai.daily_request_limit', 500);
        $today = now()->utc()->toDateString();

        return DB::transaction(function () use ($today, $siteLimit, $userId, $user, $excludeJobId) {
            $quota = GenAiDailyQuota::firstOrCreate(
                ['usage_date' => $today],
                ['request_count' => 0]
            );

            $quota = GenAiDailyQuota::where('usage_date', $today)->lockForUpdate()->first();

            if ($quota->request_count >= $siteLimit) {
                return false;
            }

            $userLimit = $user->genai_daily_quota_limit ?? -1;
            if ($userLimit >= 0) {
                $userCount = GenAiImportJob::where('user_id', $userId)
                    ->whereDate('created_at', $today)
                    ->whereIn('status', ['processing', 'parsed', 'imported'])
                    ->when($excludeJobId !== null, fn ($query) => $query->where('id', '!=', $excludeJobId))
                    ->count();

                if ($userCount >= $userLimit) {
                    return false;
                }
            }

            $quota->update([
                'request_count' => $quota->request_count + 1,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{int|null, int|null}
     */
    private function extractTokenUsage(array $response): array
    {
        $usageMetadata = $response['usageMetadata'] ?? null;
        if (is_array($usageMetadata)) {
            return [
                isset($usageMetadata['promptTokenCount']) ? (int) $usageMetadata['promptTokenCount'] : null,
                isset($usageMetadata['candidatesTokenCount']) ? (int) $usageMetadata['candidatesTokenCount'] : null,
            ];
        }

        $usage = $response['usage'] ?? null;
        if (is_array($usage)) {
            $input = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : (isset($usage['inputTokens']) ? (int) $usage['inputTokens'] : null);
            $output = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : (isset($usage['outputTokens']) ? (int) $usage['outputTokens'] : null);

            return [$input, $output];
        }

        return [null, null];
    }

    /**
     * Extract the model's text output from an Anthropic/Bedrock/Gemini-shaped response.
     *
     * @param  array<string, mixed>  $response
     */
    private function extractResponseText(array $response): string
    {
        // Anthropic / Bedrock Converse shape: content: [{type: text, text: ...}]
        $content = $response['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                    $parts[] = (string) $block['text'];
                }
            }
            if ($parts !== []) {
                return implode('', $parts);
            }
        }

        // Gemini shape: candidates[0].content.parts[].text
        $candidates = $response['candidates'] ?? null;
        if (is_array($candidates)) {
            $parts = [];
            foreach ($candidates as $candidate) {
                $candidateParts = $candidate['content']['parts'] ?? [];
                foreach ($candidateParts as $part) {
                    if (isset($part['text'])) {
                        $parts[] = (string) $part['text'];
                    }
                }
            }
            if ($parts !== []) {
                return implode('', $parts);
            }
        }

        return '';
    }

    /**
     * Decode the model's text output as JSON, falling back to TOON.
     *
     * Markdown-fence stripping + straight JSON/TOON decode covers everything
     * PhrPromptTemplate actually asks the model for; PHR never emits the
     * YAML-shaped or tabular-block TOON dialects that would need more than this.
     *
     * @return array<array-key, mixed>|null
     */
    private function decodeStructuredText(string $text): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        // Strip ```json ... ``` / ``` ... ``` markdown fences.
        if (preg_match('/^```(?:json|toon)?\s*(.*?)\s*```$/is', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        try {
            $decoded = Toon::decode($trimmed);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
