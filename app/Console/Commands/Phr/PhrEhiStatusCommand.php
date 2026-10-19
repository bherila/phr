<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiPackageSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:ehi:status {--patient= : PHR patient id} {--actor= : Acting user id} {--package= : EHI package id (default: every package of the patient)}')]
#[Description('Show preservation, accounting, clinical and binding state of EHI packages')]
class PhrEhiStatusCommand extends BaseEhiCommand
{
    public function handle(PhrPatientAccessService $accessService): int
    {
        $patient = $accessService->accessiblePatient($this->intOptionRequired('patient'), $this->intOptionRequired('actor'));
        $packages = $this->packagesFor($patient, $this->optionalIntOption('package'));
        if ($packages->isEmpty()) {
            $this->line('No EHI packages.');

            return self::SUCCESS;
        }

        foreach ($packages as $index => $package) {
            if ($index > 0) {
                $this->line('');
            }
            $this->linePackage($package, EhiPackageSummary::forPackage($package));
            if ($package->lease_expires_at !== null && $package->lease_expires_at->isFuture()) {
                $this->line('intake in progress: yes');
            }
        }

        return self::SUCCESS;
    }
}
