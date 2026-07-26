<?php

namespace App\GenAiProcessor\Jobs;

use App\GenAiProcessor\Mail\GenAiJobCompleteMail;
use App\GenAiProcessor\Mail\GenAiJobDeferredMail;
use App\GenAiProcessor\Models\GenAiDailyQuota;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\GenAiProcessor\Services\Prompts\Phr\PhrPromptTemplate;
use App\Models\User;
use App\Services\GenAiFileHelper;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
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
 * PHR's own minimal GenAI import-job queue (bherila/2025-website#1805, option (c)).
 *
 * The monorepo's App\GenAiProcessor\Jobs\ParseImportJob is a ~1,300-line class shared
 * with finance (deterministic-parser tiers, tax-document account matching, lot rebuilds,
 * class-action-email handling, etc). PHR only ever touches the "default" branch of that
 * class — the plain TOON/JSON text-output path and the PHR result-splitting logic — so
 * this job reimplements only that slice directly against the public `bherila/genai-laravel`
 * client. It intentionally has no deterministic-parser tier, no tax-document coupling, and
 * no cross-account matching.
 */
class ParseImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $jobId
    ) {
        $this->onQueue('genai-imports');
    }

    public function handle(): void
    {
        $job = GenAiImportJob::find($this->jobId);

        if (! $job || $job->status !== 'pending') {
            Log::info('ParseImportJob: skipping stale dispatch', ['job_id' => $this->jobId]);

            return;
        }

        if (! PhrStructuredDataImporter::isPhrJobType($job->job_type)) {
            $job->markFailed('Unsupported job type: '.$job->job_type);

            return;
        }

        $user = $job->user;
        if (! $user) {
            $job->markFailed('User not found');

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
            if ($fileSize > 0 && ! GenAiFileHelper::withinSizeLimit($client, $fileSize)) {
                $job->markFailed('File exceeds the size limit for the configured AI provider.');

                return;
            }

            $prompt = (new PhrPromptTemplate($job->job_type))->build($job->getContextArray());

            if (! $this->claimQuota($user->id, $user, $job->id)) {
                $job->markQueuedTomorrow();
                Log::info('ParseImportJob: quota exhausted, deferred', ['job_id' => $job->id]);

                try {
                    Mail::to($user->email)->send(new GenAiJobDeferredMail($job));
                } catch (\Throwable $mailEx) {
                    Log::warning('Failed to send deferred mail', ['job_id' => $job->id, 'error' => $mailEx->getMessage()]);
                }

                return;
            }

            $job->update([
                'status' => 'processing',
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
                $job->update($jobUpdates);
            }

            $text = $this->extractResponseText(is_array($response) ? $response : []);
            $data = $this->decodeStructuredText($text);

            if ($data === null) {
                $job->markFailed('AI returned text, but it was not valid TOON or JSON.');

                return;
            }

            DB::transaction(function () use ($job, $data): void {
                $this->createPhrResults($job, $data);
            });

            $job->markParsed();

            Log::info('ParseImportJob: success', [
                'job_id' => $job->id,
                'result_count' => $job->results()->count(),
            ]);

            try {
                Mail::to($user->email)->send(new GenAiJobCompleteMail($job));
            } catch (\Throwable $mailEx) {
                Log::warning('Failed to send completion mail', ['job_id' => $job->id, 'error' => $mailEx->getMessage()]);
            }
        } catch (GenAiRateLimitException $e) {
            $job->markFailed('API rate limit exceeded. Please wait and try again.');
        } catch (GenAiFatalException $e) {
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'retry_count' => GenAiImportJob::MAX_RETRIES,
            ]);
        } catch (\Throwable $e) {
            Log::error('ParseImportJob: unexpected error', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            $job->markFailed('An unexpected error occurred: '.$e->getMessage());
        } finally {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    /**
     * Atomically claim a quota slot for today (UTC). Mirrors the monorepo's site-wide +
     * per-user quota check (GenAiJobDispatcherService::claimQuota), trimmed to the parts
     * PHR uses.
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
     * A simplified version of the monorepo's GenAiJobDispatcherService::decodeStructuredText —
     * that version also handles YAML-shaped fallbacks and tabular-block normalization for
     * finance-specific TOON dialects PHR never emits. Markdown-fence stripping + straight
     * JSON/TOON decode covers everything PhrPromptTemplate actually asks the model for.
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

    /**
     * Create GenAiImportResult rows from parsed data. Mirrors the monorepo
     * ParseImportJob::createPhrResults() exactly (the only PHR-specific branch of that
     * method).
     *
     * @param  array<int|string, mixed>  $data
     */
    private function createPhrResults(GenAiImportJob $job, array $data): void
    {
        if ($job->job_type === 'phr_document') {
            GenAiImportResult::create([
                'job_id' => $job->id,
                'result_index' => 0,
                'result_json' => json_encode($data),
                'status' => 'pending_review',
            ]);

            return;
        }

        $records = $this->phrRecords($data);

        foreach ($records as $index => $record) {
            GenAiImportResult::create([
                'job_id' => $job->id,
                'result_index' => $index,
                'result_json' => json_encode($record),
                'status' => 'pending_review',
            ]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<int, mixed>
     */
    private function phrRecords(array $data): array
    {
        if (array_is_list($data)) {
            return $data;
        }

        foreach (['records', 'lab_results', 'vitals', 'office_visits', 'medications', 'immunizations', 'conditions', 'procedures', 'allergies'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_is_list($data[$key]) ? $data[$key] : [$data[$key]];
            }
        }

        return [$data];
    }
}
