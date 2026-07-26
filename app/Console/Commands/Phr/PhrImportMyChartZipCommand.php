<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrMyChartZipImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:import:mychart-zip {--patient= : PHR patient id} {--actor= : Acting user id} {--file= : MyChart Download My Record ZIP path}')]
#[Description('Import FHIR/CCDA files from a MyChart record ZIP into a PHR patient')]
class PhrImportMyChartZipCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, PhrMyChartZipImporter $importer): int
    {
        $patient = $this->writablePatient($accessService);
        $result = $importer->importFile($this->fileOptionRequired('file'), $patient, $this->intOptionRequired('actor'));
        $this->lineImportResult($result);

        return self::SUCCESS;
    }
}
