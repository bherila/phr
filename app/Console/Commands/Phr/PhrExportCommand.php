<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Export\PhrExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:export {--patient= : PHR patient id} {--actor= : Acting user id} {--formats=zip : Comma-separated fhir,ccda,pdf,zip} {--out= : Output file path or directory}')]
#[Description('Export a PHR patient as FHIR, CCDA, PDF, or ZIP')]
class PhrExportCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, PhrExportService $exportService): int
    {
        $patient = $this->ownedPatient($accessService);
        $out = $this->option('out');
        if (! is_string($out) || trim($out) === '') {
            $this->error('--out is required.');

            return self::FAILURE;
        }

        $formats = array_map('trim', explode(',', (string) $this->option('formats')));
        $target = $exportService->generateToPath($patient, $formats, $out);
        $this->info("Export written to {$target}");

        return self::SUCCESS;
    }
}
