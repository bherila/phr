<?php

namespace App\Console\Commands\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
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
        $pending = $this->redispatchStrandedPendingJobs($now, $pendingCutoff, $batch);

        $this->info(sprintf(
            'GenAI recovery complete: %d deferred, %d stale, and %d pending job(s) dispatched; %d exhausted stale job(s) failed.',
            $due,
            $stale,
            $pending,
            $failed,
        ));

        return self::SUCCESS;
    }

    private function recoverDueJobs(Carbon $now, int $batch): int
    {
        $jobs = GenAiImportJob::query()
            ->where('status', 'queued_tomorrow')
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

    private function redispatchStrandedPendingJobs(Carbon $now, Carbon $cutoff, int $batch): int
    {
        $jobs = GenAiImportJob::query()
            ->where('status', 'pending')
            ->where('updated_at', '<=', $cutoff)
            ->oldest('id')
            ->limit($batch)
            ->get(['id']);

        $dispatched = 0;
        foreach ($jobs as $job) {
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
                'error' => $error->getMessage(),
            ]);
            $this->error("Failed to redispatch GenAI job {$jobId}; it remains pending for the next recovery pass.");

            return false;
        }
    }
}
