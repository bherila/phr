<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiExportNotSupportedHere;
use App\Services\PHR\Import\Ehi\EhiPackageDetector;
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
            // An EHI Tables export holds no FHIR/C-CDA this loop would read; without
            // this check it would skip every member and report an empty success.
            // Name signals alone are weak, so a ZIP that also carries JSON/XML (a
            // FHIR bundle with TSV/HTML attachments) is refused only when a member
            // named after a well-known EHI table opens with a real TSV header.
            $names = [];
            $importable = false;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $names[] = $name;
                $lower = strtolower($name);
                $importable = $importable || str_ends_with($lower, '.json') || str_ends_with($lower, '.xml');
            }
            if (EhiPackageDetector::detectZipPaths($names) && (! $importable || $this->hasHeadedKnownTable($zip, $names))) {
                throw new EhiExportNotSupportedHere;
            }

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

    /** @param  list<string>  $names */
    private function hasHeadedKnownTable(ZipArchive $zip, array $names): bool
    {
        foreach ($names as $name) {
            $basename = basename(str_replace('\\', '/', $name));
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            if (! in_array($extension, ['tsv', 'txt'], true) || ! EhiPackageDetector::isKnownTableName(pathinfo($basename, PATHINFO_FILENAME))) {
                continue;
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            try {
                $line = fgets($stream, 65536);
            } finally {
                fclose($stream);
            }
            if ($line !== false && EhiPackageDetector::looksLikeHeader($line)) {
                return true;
            }
        }

        return false;
    }
}
