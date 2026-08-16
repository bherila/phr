<?php

namespace App\Jobs\PHR;

use App\Models\PhrNativeRestoreAttempt;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class PreviewPhrNativeRestoreJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    private const array RETRYABLE_FAILURES = [
        'source_unreadable',
        'temporary_storage_failed',
    ];

    public function __construct(public readonly int $attemptId)
    {
        $this->onQueue('phr-exports');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("phr-native-restore-preview:{$this->attemptId}"))->expireAfter($this->timeout + 60)];
    }

    public function handle(PhrNativeRestoreService $service): void
    {
        $attempt = PhrNativeRestoreAttempt::query()->find($this->attemptId);
        if ($attempt === null
            || (! in_array($attempt->status, [PhrNativeRestoreAttempt::STATUS_PREVIEW_PENDING, PhrNativeRestoreAttempt::STATUS_PREVIEW_PROCESSING], true)
                && ! ($attempt->status === PhrNativeRestoreAttempt::STATUS_FAILED && in_array($attempt->failure_category, self::RETRYABLE_FAILURES, true)))) {
            return;
        }

        try {
            $service->preview($attempt);
        } catch (NativeRestoreException $exception) {
            if (in_array($exception->failureCategory, self::RETRYABLE_FAILURES, true)) {
                throw $exception;
            }
        }
    }

    public function failed(): void
    {
        app(PhrNativeRestoreService::class)->markPreviewQueueFailure($this->attemptId);
    }
}
