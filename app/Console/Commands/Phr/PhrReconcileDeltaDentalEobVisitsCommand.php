<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\DeltaDentalEobVisitReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:reconcile:delta-dental-eob-visits {--patient= : PHR patient id} {--actor= : Acting user id} {--dry-run : Report changes without writing records}')]
#[Description('Reconcile Delta Dental EOB claims against dental office visits and backfill missing encounters')]
class PhrReconcileDeltaDentalEobVisitsCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, DeltaDentalEobVisitReconciler $reconciler): int
    {
        $patient = $this->writablePatient($accessService);
        $result = $reconciler->reconcile($patient, (bool) $this->option('dry-run'));
        $mode = (bool) $this->option('dry-run') ? 'Dry run' : 'Reconciled';
        $this->info("{$mode}: {$result['candidates']} candidate encounters from {$result['claims']} claims; Created: {$result['created']}; Matched: {$result['matched']}; Updated: {$result['updated']}; Evidence links: {$result['links']}");

        return self::SUCCESS;
    }
}
