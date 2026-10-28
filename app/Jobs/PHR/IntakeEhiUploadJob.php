<?php

namespace App\Jobs\PHR;

use App\Services\PHR\Import\Ehi\EhiIntakeService;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Takes in one browser-uploaded EHI ZIP. Not retried automatically: intake
 * records every outcome on the upload, and a failed one is queued again by the
 * person, where it resumes from what was already preserved.
 */
final class IntakeEhiUploadJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $uploadId)
    {
        $this->onQueue('phr-exports');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("phr-ehi-upload-intake:{$this->uploadId}"))->expireAfter($this->timeout + 60)];
    }

    public function handle(EhiUploadService $uploads): void
    {
        $uploads->runIntake($this->uploadId);
    }

    public function failed(): void
    {
        app(EhiUploadService::class)->markFailed($this->uploadId, EhiIntakeService::FAILURE_UNEXPECTED);
    }
}
