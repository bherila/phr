<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Import\MeritainEobChargeRepair;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Restores line charges an earlier EOB parser lost, from each line's stored printed text. Safe to
 * run repeatedly. It prints counts only, never a charge, a claim number or a name.
 */
#[Signature('phr:eobs:repair-charges {--patient= : Only this patient id} {--dry-run : Report what would change, without writing}')]
#[Description('Restore charges missing from imported Meritain EOB lines')]
final class PhrEobsRepairChargesCommand extends BasePhrCommand
{
    public function handle(MeritainEobChargeRepair $repair): int
    {
        $counts = $repair->run(
            $this->option('patient') !== null ? $this->intOptionRequired('patient') : null,
            (bool) $this->option('dry-run'),
        );
        foreach ($counts as $name => $count) {
            $this->line(str_replace('_', ' ', $name).': '.$count);
        }

        return self::SUCCESS;
    }
}
