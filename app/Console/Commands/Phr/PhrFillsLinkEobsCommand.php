<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrPatient;
use App\Services\PHR\Fills\FillEobLinker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Throwable;

/**
 * Brings the automatic fill-to-claim evidence up to date for one patient or all of them, for data
 * written before the linker existed or after a run of it failed. Later writes keep it current on
 * their own. Safe to run repeatedly: rerunning over unchanged data changes nothing.
 *
 * It prints counts only, never a drug, a charge, a claim number or a name. A failure is reported
 * by the exception's class, since its message could carry query bindings.
 */
#[Signature('phr:fills:link-eobs {--patient= : Only this patient id} {--dry-run : Report what would change, without writing}')]
#[Description('Link pharmacy EOB claims to medication fills as automatic evidence')]
final class PhrFillsLinkEobsCommand extends BasePhrCommand
{
    public function handle(FillEobLinker $linker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $patientIds = $this->option('patient') !== null
            ? [$this->intOptionRequired('patient')]
            : PhrPatient::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($this->option('patient') !== null && ! PhrPatient::query()->whereKey($patientIds[0])->exists()) {
            $this->error('No such patient.');

            return self::FAILURE;
        }

        $totals = [];
        $failed = 0;
        foreach ($patientIds as $patientId) {
            try {
                $counts = $dryRun ? $linker->preview($patientId) : $linker->recompute($patientId);
            } catch (Throwable $failure) {
                $failed++;
                $this->warn("Patient {$patientId} failed: ".$failure::class);

                continue;
            }
            foreach ($counts as $name => $count) {
                $totals[$name] = ($totals[$name] ?? 0) + $count;
            }
        }

        $this->info(($dryRun ? '[dry run] ' : '').'Patients: '.count($patientIds).'; failed: '.$failed);
        foreach ($totals as $name => $count) {
            $this->line(str_replace('_', ' ', $name).': '.$count);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
