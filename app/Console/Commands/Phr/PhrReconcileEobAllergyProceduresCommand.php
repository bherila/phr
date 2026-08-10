<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\MeritainEobAllergyProcedureReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:reconcile:eob-allergy-procedures {--patient= : PHR patient id} {--actor= : Acting user id} {--dry-run : Report changes without writing records}')]
#[Description('Reconcile Meritain allergy-immunotherapy claims against PHR procedures')]
class PhrReconcileEobAllergyProceduresCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, MeritainEobAllergyProcedureReconciler $reconciler): int
    {
        $patient = $this->writablePatient($accessService);
        $result = $reconciler->reconcile($patient, (bool) $this->option('dry-run'));
        $mode = (bool) $this->option('dry-run') ? 'Dry run' : 'Reconciled';
        $this->info("{$mode}: {$result['candidates']} candidate procedures from {$result['claims']} claims; Created: {$result['created']}; Matched: {$result['matched']}; Evidence links: {$result['links']}");

        return self::SUCCESS;
    }
}
