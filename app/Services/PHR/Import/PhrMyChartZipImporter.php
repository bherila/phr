<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiExportNotSupportedHere;
use App\Services\PHR\Import\Ehi\EhiPackageDetector;
use Illuminate\Support\Facades\Log;
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
            // Name signals alone are weak: genuine clinical content may accompany
            // TSV/HTML attachments. Incidental JSON/XML metadata is not clinical
            // content, and must not turn an EHI export into a skipped success.
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                $names[] = $name;
            }
            if (EhiPackageDetector::detectZipPaths($names) && ($this->hasHeadedKnownTable($zip, $names) || ! $this->hasClinicalMember($zip))) {
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

                // Routed by what the member is, as recognizes() decided, never by its
                // extension; JSON or XML that is neither format is not clinical content.
                $contents = PhrStructuredFormat::normalize($contents);
                [$format, $bundle] = PhrStructuredFormat::read($contents);
                if ($format === null) {
                    continue;
                }

                try {
                    $result->merge($format === PhrStructuredFormat::FHIR
                        ? $this->fhirImporter->importBundle((array) $bundle, $patient, $actorUserId)
                        : $this->ccdaImporter->importXml($contents, $patient, $actorUserId));
                } catch (\Throwable $exception) {
                    // Fixed text only: an exception message can carry a server path,
                    // SQL with record values, or other detail that is not for display.
                    Log::warning('MyChart ZIP member could not be imported.', ['exception' => $exception::class]);
                    $result->warn("Skipped {$name}: it could not be imported.");
                    $result->addSkipped();
                }
            }
        } finally {
            $zip->close();
        }

        return $result;
    }

    /**
     * Whether the ZIP holds a FHIR Bundle or C-CDA document to import, decided
     * without importing anything. False for an unreadable ZIP.
     */
    public function recognizes(string $path): bool
    {
        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path) !== true) {
            return false;
        }
        try {
            return $this->hasClinicalMember($zip);
        } finally {
            $zip->close();
        }
    }

    /** Recognize content without calling importers, which can write clinical rows. */
    private function hasClinicalMember(ZipArchive $zip): bool
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $extension = strtolower(pathinfo((string) $zip->getNameIndex($index), PATHINFO_EXTENSION));
            if (! in_array($extension, ['json', 'xml'], true)) {
                continue;
            }
            $contents = $zip->getFromIndex($index);
            if ($contents === false || trim($contents) === '') {
                continue;
            }
            if (PhrStructuredFormat::detect($contents) !== null) {
                return true;
            }
        }

        return false;
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
