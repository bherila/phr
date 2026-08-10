<?php

namespace App\Console\Commands\Phr;

use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\MeritainEobImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('phr:import:eobs {--patient= : PHR patient id} {--actor= : Acting user id} {--directory= : Directory containing authoritative Meritain EOB PDFs} {--dry-run : Parse and report without writing documents or records}')]
#[Description('Import authoritative Meritain EOB PDFs as EOB documents and structured claim lines')]
class PhrImportEobsCommand extends BasePhrCommand
{
    public function handle(PhrPatientAccessService $accessService, MeritainEobImporter $importer): int
    {
        $patient = $this->writablePatient($accessService);
        $directory = $this->option('directory');
        if (! is_string($directory) || trim($directory) === '') {
            throw new \InvalidArgumentException('--directory must point to a readable directory.');
        }

        $result = $importer->importDirectory($patient, (int) $this->option('actor'), $directory, (bool) $this->option('dry-run'));
        $mode = (bool) $this->option('dry-run') ? 'Dry run' : 'Imported';
        $this->info("{$mode}: {$result['imported']}; Scanned: {$result['scanned']}; Skipped: {$result['skipped']}; Duplicates: {$result['duplicates']}; Lines: {$result['lines']}; Failures: {$result['failures']}");
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
