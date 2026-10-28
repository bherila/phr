<?php

namespace App\Jobs\PHR;

use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Services\PHR\Import\Ehi\EhiPreviewQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one requested EHI preview. Not retried automatically: the run records
 * every outcome, and an interrupted projection resumes when the preview is
 * requested again.
 */
final class PreviewEhiPackageJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('phr-exports');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("phr-ehi-preview-run:{$this->runId}"))->expireAfter($this->timeout + 60)];
    }

    public function handle(EhiPreviewQueue $previews): void
    {
        $previews->run($this->runId);
    }

    public function failed(): void
    {
        app(EhiPreviewQueue::class)->markFailed($this->runId, EhiIntakeService::FAILURE_UNEXPECTED);
    }
}
