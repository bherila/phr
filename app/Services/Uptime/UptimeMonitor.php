<?php

namespace App\Services\Uptime;

use App\Models\UptimeRun;
use Closure;
use Throwable;

final class UptimeMonitor
{
    /**
     * @param  Closure(): int  $operation
     */
    public function run(string $jobName, Closure $operation): int
    {
        UptimeJobCatalog::get($jobName);

        $startedAt = now();
        $startedNanoseconds = hrtime(true);
        $run = UptimeRun::query()->create([
            'job_name' => $jobName,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            $exitCode = $operation();
        } catch (Throwable $exception) {
            $this->finish($run, 1, $startedNanoseconds);

            throw $exception;
        }

        $this->finish($run, $exitCode, $startedNanoseconds);

        return $exitCode;
    }

    private function finish(UptimeRun $run, int $exitCode, int $startedNanoseconds): void
    {
        $run->forceFill([
            'status' => $exitCode === 0 ? 'success' : 'failure',
            'finished_at' => now(),
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedNanoseconds) / 1_000_000)),
            'exit_code' => min(65535, max(0, $exitCode)),
        ])->save();
    }
}
