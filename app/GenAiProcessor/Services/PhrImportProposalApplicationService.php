<?php

namespace App\GenAiProcessor\Services;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Services\PHR\Import\PhrImportProposalDao;
use Closure;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/** Atomically turns validated model output into reviewable PHR proposals. */
final readonly class PhrImportProposalApplicationService
{
    public function __construct(private PhrImportProposalDao $proposals) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @param  (Closure(GenAiImportJob): void)|null  $authorizeLockedJob
     */
    public function apply(int $jobId, array $data, ?Closure $authorizeLockedJob = null): int
    {
        return DB::transaction(function () use ($jobId, $data, $authorizeLockedJob): int {
            $job = GenAiImportJob::query()->lockForUpdate()->findOrFail($jobId);
            if (in_array($job->status, ['parsed', 'imported'], true)) {
                return 0;
            }
            $authorizeLockedJob?->__invoke($job);
            if (! in_array($job->status, ['pending', 'processing'], true)) {
                throw new UnexpectedValueException('The import job no longer accepts extraction results.');
            }
            if ($job->results()->exists()) {
                throw new UnexpectedValueException('The import job already has proposals in a non-terminal state.');
            }

            $created = $this->proposals->createForJob($job, $data);
            $job->update([
                'status' => 'parsed',
                'parsed_at' => now(),
                'error_message' => null,
            ]);

            return $created;
        });
    }
}
