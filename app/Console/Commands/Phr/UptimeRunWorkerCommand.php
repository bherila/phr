<?php

namespace App\Console\Commands\Phr;

use App\Services\Uptime\UptimeJobCatalog;
use App\Services\Uptime\UptimeMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Artisan;

#[Signature('phr:uptime:run-worker')]
#[Description('Drain the managed PHR queues with sanitized cPanel cron history')]
class UptimeRunWorkerCommand extends BasePhrCommand
{
    public function handle(UptimeMonitor $monitor): int
    {
        return $monitor->run(
            UptimeJobCatalog::QUEUE_WORKER,
            fn (): int => Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'genai-imports,phr-exports',
                '--stop-when-empty' => true,
                '--max-time' => 240,
                '--timeout' => 300,
                '--sleep' => 1,
            ], $this->output),
        );
    }
}
