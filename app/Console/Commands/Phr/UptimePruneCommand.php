<?php

namespace App\Console\Commands\Phr;

use App\Models\UptimeRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:uptime:prune {--days=30 : Retention window in days}')]
#[Description('Delete expired sanitized uptime run history')]
class UptimePruneCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 3650],
        ]);

        if ($days === false) {
            $this->error('--days must be an integer between 1 and 3650.');

            return self::INVALID;
        }

        $deleted = UptimeRun::query()
            ->where('started_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} uptime run(s).");

        return self::SUCCESS;
    }
}
