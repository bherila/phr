<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrImportResult;
use Illuminate\Console\Command;

abstract class BasePhrCommand extends Command
{
    protected function intOptionRequired(string $name): int
    {
        $value = $this->option($name);
        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            throw new \InvalidArgumentException("--{$name} must be an integer.");
        }

        return (int) $value;
    }

    protected function fileOptionRequired(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '' || ! is_readable($value)) {
            throw new \InvalidArgumentException("--{$name} must be a readable file path.");
        }

        return $value;
    }

    protected function writablePatient(PhrPatientAccessService $accessService): PhrPatient
    {
        return $accessService->writablePatient(
            $this->intOptionRequired('patient'),
            $this->intOptionRequired('actor'),
        );
    }

    protected function ownedPatient(PhrPatientAccessService $accessService): PhrPatient
    {
        return $accessService->ownedPatient(
            $this->intOptionRequired('patient'),
            $this->intOptionRequired('actor'),
        );
    }

    protected function lineImportResult(PhrImportResult $result): void
    {
        $this->info("Created: {$result->created}; Updated: {$result->updated}; Documents: {$result->documents}; Skipped: {$result->skipped}");
        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }
    }
}
