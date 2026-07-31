<?php

namespace App\Console\Commands\Phr;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('phr:queue:audit')]
#[Description('Report queue backlog metadata without reading or printing job payloads')]
class PhrQueueAuditCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $queueConnection = $this->connection(config('queue.connections.database.connection'));
        $queueTable = (string) config('queue.connections.database.table', 'jobs');
        $failedConnection = $this->connection(config('queue.failed.database'));
        $failedTable = (string) config('queue.failed.table', 'failed_jobs');
        $now = now()->timestamp;

        $pendingRows = $queueConnection->table($queueTable)
            ->selectRaw('queue, COUNT(*) AS job_count, SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved_count, MAX(attempts) AS max_attempts, MIN(created_at) AS oldest_created_at')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $failedRows = $failedConnection->table($failedTable)
            ->selectRaw('queue, COUNT(*) AS job_count, MIN(failed_at) AS oldest_failed_at')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $pendingTotal = $pendingRows->sum(fn (object $row): int => (int) $row->job_count);
        $failedTotal = $failedRows->sum(fn (object $row): int => (int) $row->job_count);

        $this->line(sprintf(
            'queue-audit driver=%s retry_after=%d pending_total=%d failed_total=%d',
            (string) config('queue.default'),
            (int) config('queue.connections.database.retry_after'),
            $pendingTotal,
            $failedTotal,
        ));

        foreach ($pendingRows as $row) {
            $oldestCreatedAt = (int) $row->oldest_created_at;
            $this->line(sprintf(
                'pending queue=%s count=%d reserved=%d max_attempts=%d oldest_seconds=%d',
                (string) $row->queue,
                (int) $row->job_count,
                (int) $row->reserved_count,
                (int) $row->max_attempts,
                max(0, $now - $oldestCreatedAt),
            ));
        }

        foreach ($failedRows as $row) {
            $oldestFailedAt = Carbon::parse((string) $row->oldest_failed_at);
            $this->line(sprintf(
                'failed queue=%s count=%d oldest_seconds=%d',
                (string) $row->queue,
                (int) $row->job_count,
                max(0, $oldestFailedAt->diffInSeconds(now())),
            ));
        }

        return self::SUCCESS;
    }

    private function connection(mixed $configuredConnection): Connection
    {
        $name = is_string($configuredConnection) && $configuredConnection !== ''
            ? $configuredConnection
            : null;

        return DB::connection($name);
    }
}
