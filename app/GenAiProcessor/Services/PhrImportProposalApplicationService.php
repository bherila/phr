<?php

namespace App\GenAiProcessor\Services;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\User;
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
     * @param  (Closure(GenAiImportJob, User): void)|null  $authorizeLockedJob
     */
    public function apply(int $jobId, array $data, ?Closure $authorizeLockedJob = null): int
    {
        $ownerId = GenAiImportJob::query()->findOrFail($jobId)->user_id;

        return DB::transaction(function () use ($jobId, $ownerId, $data, $authorizeLockedJob): int {
            // Execution-mode changes lock the user and then their jobs. Keep the
            // same order so a provider result cannot cross that transaction.
            $user = User::query()->lockForUpdate()->findOrFail($ownerId);
            $job = GenAiImportJob::query()->lockForUpdate()->findOrFail($jobId);
            if ((int) $job->user_id !== (int) $user->id) {
                throw new UnexpectedValueException('The import job owner changed unexpectedly.');
            }
            $authorizeLockedJob?->__invoke($job, $user);
            if (in_array($job->status, ['parsed', 'imported'], true)) {
                return 0;
            }
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

    /** @param array<array-key, mixed> $data */
    public function applyApiResult(int $jobId, array $data): int
    {
        return $this->apply(
            $jobId,
            $data,
            static function (GenAiImportJob $job, User $user): void {
                if ($job->execution_mode !== GenAiImportJob::EXECUTION_API
                    || $user->genAiExecutionMode() !== GenAiImportJob::EXECUTION_API) {
                    throw new PhrImportExecutionModeChanged('The import no longer permits API execution results.');
                }
            },
        );
    }
}
