<?php

namespace App\GenAiProcessor\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PHR's own minimal GenAI import-job queue. Mirrors the shape of the monorepo's
 * finance+PHR GenAiImportJob model (bherila/2025-website#1805, option (c)), but
 * scoped to PHR job types only — the two apps intentionally diverge from here.
 */
class GenAiImportJob extends Model
{
    protected $table = 'genai_import_jobs';

    public const VALID_JOB_TYPES = [
        'phr_lab_result',
        'phr_vital',
        'phr_office_visit',
        'phr_medication',
        'phr_immunization',
        'phr_problem_list',
        'phr_procedure',
        'phr_allergy',
        'phr_portal_message',
        'phr_negative_assertion',
        'phr_document',
    ];

    public const VALID_STATUSES = [
        'pending',
        'processing',
        'parsed',
        'imported',
        'failed',
        'queued_tomorrow',
    ];

    public const MAX_RETRIES = 3;

    /** A registered deterministic parser produced the result; AI was used only to verify. */
    public const TIER_DETERMINISTIC = 'deterministic';

    /** A parser matched but failed; the full-AI extraction path produced the result. */
    public const TIER_AI_FALLBACK = 'ai_fallback';

    /** No parser matched; the full-AI extraction path produced the result. */
    public const TIER_AI_ONLY = 'ai_only';

    protected $fillable = [
        'user_id',
        'ai_configuration_id',
        'ai_provider',
        'ai_model',
        'job_type',
        'file_hash',
        'original_filename',
        's3_path',
        'mime_type',
        'file_size_bytes',
        'context_json',
        'status',
        'error_message',
        'raw_response',
        'retry_count',
        'scheduled_for',
        'parsed_at',
        'input_tokens',
        'output_tokens',
        'processing_tier',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'retry_count' => 'integer',
        'scheduled_for' => 'date',
        'parsed_at' => 'datetime',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // Always clean up the S3/R2 file when a job record is deleted.
        static::deleting(function (self $job): void {
            if (! empty($job->s3_path)) {
                try {
                    Storage::disk('s3')->delete($job->s3_path);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete S3 file for GenAI job during model delete', [
                        'job_id' => $job->id,
                        's3_path' => $job->s3_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GenAiImportResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(GenAiImportResult::class, 'job_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextArray(): array
    {
        if (empty($this->context_json)) {
            return [];
        }

        return json_decode($this->context_json, true) ?? [];
    }

    public function canRetry(): bool
    {
        return $this->retry_count < self::MAX_RETRIES && $this->status === 'failed';
    }

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markParsed(): void
    {
        $this->update([
            'status' => 'parsed',
            'parsed_at' => now(),
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    public function markQueuedTomorrow(): void
    {
        $this->update([
            'status' => 'queued_tomorrow',
            'scheduled_for' => now()->utc()->addDay()->startOfDay(),
        ]);
    }

    public function markImported(): void
    {
        $this->update(['status' => 'imported']);
    }
}
