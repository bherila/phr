<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrCcdaImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:import:ccda {--patient= : PHR patient id} {--actor= : Acting user id} {--file= : CCDA XML path}')]
#[Description('Import a CCDA XML document into a PHR patient')]
class PhrImportCcdaCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, PhrCcdaImporter $importer): int
    {
        $patient = $this->writablePatient($accessService);
        $result = $importer->importFile($this->fileOptionRequired('file'), $patient, $this->intOptionRequired('actor'));
        $this->lineImportResult($result);

        return self::SUCCESS;
    }
}
