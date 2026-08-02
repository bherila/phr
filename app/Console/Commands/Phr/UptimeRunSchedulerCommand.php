<?php

namespace App\Console\Commands\Phr;

use App\Services\Uptime\UptimeJobCatalog;
use App\Services\Uptime\UptimeMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Artisan;

#[Signature('phr:uptime:run-scheduler')]
#[Description('Run the Laravel scheduler with sanitized cPanel cron history')]
class UptimeRunSchedulerCommand extends BasePhrCommand
{
    public function handle(UptimeMonitor $monitor): int
    {
        return $monitor->run(
            UptimeJobCatalog::SCHEDULER,
            fn (): int => Artisan::call('schedule:run', ['--no-interaction' => true], $this->output),
        );
    }
}
