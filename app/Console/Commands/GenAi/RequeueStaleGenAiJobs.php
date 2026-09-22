<?php

namespace App\Console\Commands\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Support\PhrExternalImportStatusMap;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequeueStaleGenAiJobs extends Command
{
    protected $signature = 'genai:requeue-stale
                            {--stale-minutes=10 : Recover processing jobs untouched for this many minutes}
                            {--pending-minutes=5 : Redispatch pending jobs untouched for this many minutes}
                            {--batch=100 : Maximum jobs to inspect in each state}';

    protected $description = 'Redispatch due, stale, or stranded GenAI import jobs';

    public function handle(): int
    {
        $now = now();
        $staleCutoff = $now->copy()->subMinutes($this->positiveOption('stale-minutes'));
        $pendingCutoff = $now->copy()->subMinutes($this->positiveOption('pending-minutes'));
        $batch = min(1000, $this->positiveOption('batch'));

        $due = $this->recoverDueJobs($now, $batch);
        [$stale, $failed] = $this->recoverStaleProcessingJobs($now, $staleCutoff, $batch);
        // Reconcile before redispatching so the pending pass sees a truthful
        // status: this is what stops a local timer from leaving an import
        // "processing" while no external client holds a lease.
        $reconciled = $this->reconcileExternalJobs($batch);
        $pending = $this->redispatchStrandedPendingJobs($now, $pendingCutoff, $batch);

        $this->info(sprintf(
            'GenAI recovery complete: %d deferred, %d stale, and %d pending job(s) dispatched; %d exhausted stale job(s) failed; %d external job(s) reconciled.',
            $due,
            $stale,
            $pending,
            $failed,
            $reconciled,
        ));

        return self::SUCCESS;
    }

    private function recoverDueJobs(Carbon $now, int $batch): int
    {
        $jobs = GenAiImportJob::query()
            ->where('status', 'queued_tomorrow')
            ->where('execution_mode', GenAiImportJob::EXECUTION_API)
            ->whereDate('scheduled_for', '<=', $now->utc()->toDateString())
            ->oldest('id')
            ->limit($batch)
            ->get(['id']);

        $dispatched = 0;
        foreach ($jobs as $job) {
            $updated = GenAiImportJob::query()
                ->whereKey($job->id)
                ->where('status', 'queued_tomorrow')
                ->whereDate('scheduled_for', '<=', $now->utc()->toDateString())
                ->update([
                    'status' => 'pending',
                    'scheduled_for' => null,
                    'error_message' => null,
                    'updated_at' => $now,
                ]);

            if ($updated === 1) {
                $dispatched += (int) $this->dispatch($job->id);
            }
        }

        return $dispatched;
    }

    /**
     * @return array{int, int}
     */
    private function recoverStaleProcessingJobs(Carbon $now, Carbon $cutoff, int $batch): array
    {
        $jobs = GenAiImportJob::query()
            ->where('status', 'processing')
            ->where('execution_mode', GenAiImportJob::EXECUTION_API)
            ->where('updated_at', '<=', $cutoff)
            ->oldest('id')
            ->limit($batch)
            ->get(['id', 'retry_count']);

        $dispatched = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            if ($job->retry_count >= GenAiImportJob::MAX_RETRIES) {
                $updated = GenAiImportJob::query()
                    ->whereKey($job->id)
                    ->where('status', 'processing')
                    ->where('updated_at', '<=', $cutoff)
                    ->update([
                        'status' => 'failed',
                        'error_message' => 'Job timed out after exhausting stale-recovery retries.',
                        'updated_at' => $now,
                    ]);
                $failed += $updated;

                continue;
            }

            $updated = GenAiImportJob::query()
                ->whereKey($job->id)
                ->where('status', 'processing')
                ->where('updated_at', '<=', $cutoff)
                ->update([
                    'status' => 'pending',
                    'scheduled_for' => null,
                    'error_message' => 'Job timed out and was redispatched by stale recovery.',
                    'retry_count' => DB::raw('retry_count + 1'),
                    'updated_at' => $now,
                ]);

            if ($updated === 1) {
                $dispatched += (int) $this->dispatch($job->id);
            }
        }

        return [$dispatched, $failed];
    }

    /**
     * Conform external imports to their durable package request.
     *
     * The package row is authoritative: it is the only record of whether a
     * client currently holds a live lease. This pass never changes retry
     * counters or package backoff — it only stops the user-visible status
     * from claiming work is in flight when the queue says otherwise.
     *
     * The batch is drawn from rows the mapping table actually disagrees with.
     * Reconciling a row the map already agrees with is a no-op that leaves it
     * in the same id-ordered position, so selecting on link alone would hand
     * every scheduled run the same oldest rows and never reach an import
     * behind them.
     */
    private function reconcileExternalJobs(int $batch): int
    {
        $jobs = PhrExternalImportStatusMap::scopeReconcilable(GenAiImportJob::query())
            ->oldest('id')
            ->limit($batch)
            ->get(['id', 'mcp_request_id']);

        $reconciled = 0;
        foreach ($jobs as $job) {
            $reconciled += (int) PhrExternalImportStatusMap::reconcileRequestId((string) $job->mcp_request_id);
        }

        return $reconciled;
    }

    /**
     * Redispatch pending imports nothing else is going to pick up.
     *
     * An external request that the queue still owns carries the package's own
     * retry backoff in `available_at`; redispatching it would only re-claim the
     * row locally and reset that display state. Those rows are excluded before
     * the limit rather than skipped after it: skipping leaves `updated_at`
     * untouched, so a batch of backed-off requests would stay at the head of
     * the id ordering and hide every later stranded job forever.
     */
    private function redispatchStrandedPendingJobs(Carbon $now, Carbon $cutoff, int $batch): int
    {
        $jobs = PhrExternalImportStatusMap::scopeQueueDoesNotOwnWork(
            GenAiImportJob::query()
                ->where('status', 'pending')
                ->where('updated_at', '<=', $cutoff)
        )
            ->oldest('id')
            ->limit($batch)
            ->get(['id', 'execution_mode', 'mcp_request_id']);

        $dispatched = 0;
        foreach ($jobs as $job) {
            // The selection above is a snapshot; a client can claim a request
            // between it and this write, so ownership is re-checked here.
            if ($this->externalQueueOwnsWork($job)) {
                continue;
            }
            // Touching the row is the command's compare-and-swap claim. A
            // concurrent recovery invocation will no longer consider it stale.
            $updated = GenAiImportJob::query()
                ->whereKey($job->id)
                ->where('status', 'pending')
                ->where('updated_at', '<=', $cutoff)
                ->update(['updated_at' => $now]);

            if ($updated === 1) {
                $dispatched += (int) $this->dispatch($job->id);
            }
        }

        return $dispatched;
    }

    private function externalQueueOwnsWork(GenAiImportJob $job): bool
    {
        if ($job->execution_mode !== GenAiImportJob::EXECUTION_EXTERNAL || $job->mcp_request_id === null) {
            return false;
        }

        return PhrExternalImportStatusMap::queueOwnsWork(
            McpRequest::query()->find($job->mcp_request_id)
        );
    }

    private function positiveOption(string $name): int
    {
        return max(1, (int) $this->option($name));
    }

    private function dispatch(int $jobId): bool
    {
        try {
            ParseImportJob::dispatch($jobId);

            return true;
        } catch (Throwable $error) {
            // The row remains pending. Its updated_at claim prevents a hot
            // retry loop, and the pending recovery pass will try it again
            // after --pending-minutes.
            Log::error('Failed to redispatch recovered GenAI job', [
                'job_id' => $jobId,
                'exception' => $error::class,
            ]);
            $this->error("Failed to redispatch GenAI job {$jobId}; it remains pending for the next recovery pass.");

            return false;
        }
    }
}
