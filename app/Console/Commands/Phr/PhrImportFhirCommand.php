<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrFhirImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:import:fhir {--patient= : PHR patient id} {--actor= : Acting user id} {--file= : FHIR R4 Bundle JSON path}')]
#[Description('Import a FHIR R4 Bundle into a PHR patient')]
class PhrImportFhirCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, PhrFhirImporter $importer): int
    {
        $patient = $this->writablePatient($accessService);
        $result = $importer->importFile($this->fileOptionRequired('file'), $patient, $this->intOptionRequired('actor'));
        $this->lineImportResult($result);

        return self::SUCCESS;
    }
}
