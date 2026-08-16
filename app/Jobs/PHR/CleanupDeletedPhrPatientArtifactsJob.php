<?php

namespace App\Jobs\PHR;

use App\Services\PHR\DataHub\PhrPatientDeletionCleanupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class CleanupDeletedPhrPatientArtifactsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $deletionId)
    {
        $this->onQueue('phr-exports');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("phr-patient-deletion:{$this->deletionId}"))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(PhrPatientDeletionCleanupService $cleanup): void
    {
        $cleanup->cleanup($this->deletionId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(PhrPatientDeletionCleanupService::class)->markQueueFailure($this->deletionId);
    }
}
