<?php

namespace App\Console\Commands\Phr;

use App\Services\Uptime\UptimeJobCatalog;
use App\Services\Uptime\UptimeMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

#[Signature('phr:uptime:run-task {job : Fixed monitored task identifier}')]
#[Description('Run one allow-listed PHR scheduled task with sanitized uptime history')]
class UptimeRunTaskCommand extends BasePhrCommand
{
    public function handle(UptimeMonitor $monitor): int
    {
        $job = (string) $this->argument('job');
        try {
            $command = UptimeJobCatalog::scheduledCommand($job);
        } catch (InvalidArgumentException) {
            $this->error('Unknown monitored task identifier.');

            return self::INVALID;
        }

        return $monitor->run(
            $job,
            fn (): int => Artisan::call($command, [], $this->output),
        );
    }
}
