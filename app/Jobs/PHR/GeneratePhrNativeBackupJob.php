<?php

namespace App\Jobs\PHR;

use App\Models\PhrNativeBackup;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePhrNativeBackupJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    /** Ensure a hard worker timeout does not leave the row stuck in processing. */
    public bool $failOnTimeout = true;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public int $backupId)
    {
        $this->onQueue('phr-exports');
    }

    public function handle(PhrNativeBackupService $backupService): void
    {
        $backup = PhrNativeBackup::query()->find($this->backupId);
        if ($backup === null) {
            return;
        }
        if (! in_array($backup->status, [
            PhrNativeBackup::STATUS_PENDING,
            PhrNativeBackup::STATUS_PROCESSING,
            PhrNativeBackup::STATUS_FAILED,
        ], true)) {
            return;
        }

        $backupService->generate($backup);
    }

    public function failed(?\Throwable $exception): void
    {
        app(PhrNativeBackupService::class)->markQueueFailure($this->backupId);
    }
}
