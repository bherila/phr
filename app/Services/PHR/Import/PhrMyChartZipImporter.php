<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use InvalidArgumentException;
use ZipArchive;

class PhrMyChartZipImporter
{
    public function __construct(
        private PhrFhirImporter $fhirImporter,
        private PhrCcdaImporter $ccdaImporter,
    ) {}

    public function importFile(string $path, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("MyChart ZIP is not readable: {$path}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException("Unable to open MyChart ZIP: {$path}");
        }

        $result = new PhrImportResult;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $lower = strtolower($name);
                if (! str_ends_with($lower, '.json') && ! str_ends_with($lower, '.xml')) {
                    continue;
                }

                $contents = $zip->getFromIndex($index);
                if ($contents === false) {
                    $result->warn("Unable to read {$name} from ZIP.");

                    continue;
                }

                $temp = tempnam(sys_get_temp_dir(), 'phr-import-');
                if ($temp === false) {
                    throw new InvalidArgumentException('Unable to create temporary file for ZIP entry.');
                }

                file_put_contents($temp, $contents);

                try {
                    $result->merge(str_ends_with($lower, '.json')
                        ? $this->fhirImporter->importFile($temp, $patient, $actorUserId)
                        : $this->ccdaImporter->importFile($temp, $patient, $actorUserId));
                } catch (\Throwable $exception) {
                    $result->warn("Skipped {$name}: ".$exception->getMessage());
                    $result->addSkipped();
                } finally {
                    @unlink($temp);
                }
            }
        } finally {
            $zip->close();
        }

        return $result;
    }
}
