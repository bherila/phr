<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiExportNotSupportedHere;
use App\Services\PHR\Import\Ehi\EhiPackageDetector;
use DOMDocument;
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
            if ($extension === 'json') {
                $bundle = json_decode($contents, true);
                if (is_array($bundle) && ($bundle['resourceType'] ?? null) === 'Bundle'
                    && (! isset($bundle['entry']) || is_array($bundle['entry']))) {
                    return true;
                }
                unset($bundle);

                continue;
            }

            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $loaded = $document->loadXML($contents, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if ($loaded && $document->documentElement?->localName === 'ClinicalDocument'
                && $document->documentElement->namespaceURI === 'urn:hl7-org:v3') {
                return true;
            }
            unset($document);
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
