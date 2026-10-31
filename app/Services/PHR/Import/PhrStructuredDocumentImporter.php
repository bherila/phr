<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiPackageDetector;
use App\Services\PHR\Import\Ehi\EhiUploadRefused;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use ZipArchive;

/**
 * Imports a stored document with PHR's own parsers when it is a format they
 * read, so an agent's imports.create gets the same non-AI import the browser
 * does: a C-CDA or FHIR file is imported directly (pending review) by the same
 * PhrStructuredFileImporter as the browser route, and a ZIP is staged as an
 * upload whose intake reads it as an EHI Tables or MyChart export. Anything
 * else returns null, and the caller queues AI extraction.
 */
final class PhrStructuredDocumentImporter
{
    /** Read whole into memory to recognize it: the same bound as a browser's single file. */
    public const int MAX_DIRECT_BYTES = 50 * 1024 * 1024;

    private const string ZIP_SIGNATURE = "PK\x03\x04";

    private const int PREFIX_BYTES = 4096;

    public function __construct(
        private readonly PhrStructuredFileImporter $files,
        private readonly EhiUploadService $uploads,
        private readonly PhrMyChartZipImporter $myChart,
    ) {}

    public function import(PhrPatient $patient, int $actorUserId, PhrDocument $document): ?PhrStructuredDocumentImport
    {
        if ($document->storage_disk !== PhrDocument::STORAGE_DISK || $document->storage_path === null) {
            return null;
        }
        $disk = Storage::disk(PhrDocument::STORAGE_DISK);
        if (! $disk->exists($document->storage_path)) {
            return null;
        }

        $prefix = $this->prefix($document);
        if (str_starts_with($prefix, self::ZIP_SIGNATURE) && $this->isExport($disk->path($document->storage_path))) {
            try {
                [$upload, $queued] = $this->uploads->stageDocument($patient, $actorUserId, $document);
            } catch (EhiUploadRefused $refused) {
                throw new HttpException($refused->status(), match ($refused->reason) {
                    EhiUploadRefused::PATIENT_ARCHIVED => 'The patient is archived; nothing new is imported into it.',
                    EhiUploadRefused::TOO_LARGE => 'The document is larger than an export PHR can import.',
                    default => 'The document could not be staged for import; try again.',
                });
            }

            return PhrStructuredDocumentImport::staged($upload, $queued);
        }

        // Only text that opens like JSON or XML can be C-CDA or FHIR: a PDF or an
        // image goes to AI without being read whole.
        $opening = PhrStructuredFormat::normalize($prefix);
        if ($opening === '' || ! in_array($opening[0], ['{', '<'], true)) {
            return null;
        }
        if ((int) $document->byte_size > self::MAX_DIRECT_BYTES) {
            // Too large to read whole. One that announces itself as C-CDA or FHIR
            // is refused, never sent to AI; anything else goes to AI as before.
            if (preg_match('/<(?:\w+:)?ClinicalDocument\b|"resourceType"\s*:\s*"Bundle"/', $opening) === 1) {
                throw new UnprocessableEntityHttpException('The document is a C-CDA or FHIR file larger than PHR imports directly (50 MB); nothing was imported.');
            }

            return null;
        }
        try {
            [$format, $result] = $this->files->import($patient, $actorUserId, (string) $disk->get($document->storage_path));
        } catch (PhrStructuredImportRefused $refused) {
            if ($refused->reason === PhrStructuredImportRefused::UNSUPPORTED_FORMAT) {
                // Not a format PHR's parsers read: the caller queues AI extraction.
                return null;
            }
            // Recognized, so no AI: a structured file is parsed deterministically or refused.
            throw new UnprocessableEntityHttpException(match ($refused->reason) {
                PhrStructuredImportRefused::MULTIPLE_PATIENTS => 'The document holds records for more than one person (different birth dates); nothing was imported.',
                default => 'The document was recognized as C-CDA or FHIR but could not be imported; nothing was imported.',
            });
        }

        return PhrStructuredDocumentImport::imported($format, $result);
    }

    /**
     * An EHI Tables export (by its table paths) or a MyChart ZIP (holding a FHIR
     * bundle or C-CDA document). Any other ZIP, a .docx among them, is not one,
     * and goes to AI extraction as before.
     */
    private function isExport(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }
        try {
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $names[] = (string) $zip->getNameIndex($index);
            }
        } finally {
            $zip->close();
        }

        return EhiPackageDetector::detectZipPaths($names) || $this->myChart->recognizes($path);
    }

    /** The document's first bytes: enough to tell a ZIP, JSON or XML from anything else. */
    private function prefix(PhrDocument $document): string
    {
        $stream = Storage::disk(PhrDocument::STORAGE_DISK)->readStream((string) $document->storage_path);
        if (! is_resource($stream)) {
            return '';
        }
        try {
            return (string) fread($stream, self::PREFIX_BYTES);
        } finally {
            fclose($stream);
        }
    }
}
