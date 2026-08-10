<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\MeritainEobVisitReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:reconcile:eob-visits {--patient= : PHR patient id} {--actor= : Acting user id} {--dry-run : Report changes without writing records}')]
#[Description('Reconcile Meritain E/M claims against office visits and backfill missing encounters')]
class PhrReconcileEobVisitsCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, MeritainEobVisitReconciler $reconciler): int
    {
        $patient = $this->writablePatient($accessService);
        $result = $reconciler->reconcile($patient, (bool) $this->option('dry-run'));
        $mode = (bool) $this->option('dry-run') ? 'Dry run' : 'Reconciled';
        $this->info("{$mode}: {$result['candidates']} candidate encounters from {$result['claims']} claims; Created: {$result['created']}; Matched: {$result['matched']}; Updated: {$result['updated']}; Evidence links: {$result['links']}");

        return self::SUCCESS;
    }
}
