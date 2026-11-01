<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Imports one C-CDA or FHIR file directly, the same way for the browser route
 * and an agent's imports.create: recognized from its normalized content (a FHIR
 * bundle decoded once), refused if it names more than one person, and written
 * all or nothing, pending review.
 */
final class PhrStructuredFileImporter
{
    public function __construct(
        private readonly PhrCcdaImporter $ccda,
        private readonly PhrFhirImporter $fhir,
        private readonly PhrPatientArtifactWriteGuard $writeGuard,
    ) {}

    /**
     * `$document` is the stored document `$contents` was read from, if any; every
     * row written is linked to it.
     *
     * @return array{0: PhrStructuredFormat::FHIR|PhrStructuredFormat::CCDA, 1: PhrImportResult}
     *
     * @throws PhrStructuredImportRefused nothing was written
     */
    public function import(PhrPatient $patient, int $actorUserId, string $contents, ?PhrDocument $document = null): array
    {
        $contents = PhrStructuredFormat::normalize($contents);
        [$format, $bundle] = PhrStructuredFormat::read($contents);
        if ($format === null) {
            throw new PhrStructuredImportRefused(PhrStructuredImportRefused::UNSUPPORTED_FORMAT);
        }

        $patients = new PhrSourcePatients;
        $format === PhrStructuredFormat::FHIR ? $patients->addFhirBundle((array) $bundle) : $patients->addCcda($contents);
        if ($patients->multiple()) {
            throw new PhrStructuredImportRefused(PhrStructuredImportRefused::MULTIPLE_PATIENTS);
        }

        try {
            // All of the file or none of it: an entry that fails partway must not
            // leave the entries before it written, unsummarized. Under the patient
            // lock, so two imports of one source cannot race on the same records;
            // the guard retries a deadlock before any failure is reported.
            $result = $this->writeGuard->run((int) $patient->id, fn (PhrPatient $locked): PhrImportResult => $format === PhrStructuredFormat::CCDA
                ? $this->ccda->importXml($contents, $locked, $actorUserId, $document)
                : $this->fhir->importBundle((array) $bundle, $locked, $actorUserId, $document));
        } catch (Throwable $failure) {
            if (! $failure instanceof InvalidArgumentException) {
                Log::warning('Structured file import failed.', ['exception' => $failure::class]);
            }

            throw new PhrStructuredImportRefused(PhrStructuredImportRefused::UNREADABLE);
        }

        return [$format, $result];
    }
}
