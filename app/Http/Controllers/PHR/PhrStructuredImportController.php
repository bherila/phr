<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\Ehi\EhiUploadRefused;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Services\PHR\Import\PhrStructuredFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Imports one C-CDA or FHIR file from the browser (issue #235, PR 3). The format
 * is recognized from the content, and the file goes to the importer
 * `phr:import:ccda` or `phr:import:fhir` uses. Records are written directly and
 * land pending review; the response counts what was written for a summary. A
 * ZIP goes through the chunked upload instead, which handles EHI Tables and
 * MyChart exports.
 */
final class PhrStructuredImportController extends Controller
{
    /** Below the proxy's per-request body limit, with room for the form encoding. */
    public const int MAX_KILOBYTES = 50 * 1024;

    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly EhiUploadService $uploads,
        private readonly PhrCcdaImporter $ccda,
        private readonly PhrFhirImporter $fhir,
    ) {}

    public function store(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate(['file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES]]);
        $actor = (int) $request->user()?->id;
        $patientModel = $this->access->writablePatient($patient, $actor);
        try {
            $this->uploads->ensureWritable($patientModel, $actor);
        } catch (EhiUploadRefused $refused) {
            return $this->privateJson(['error' => $refused->reason], $refused->status());
        }

        $contents = PhrStructuredFormat::normalize((string) file_get_contents($validated['file']->getRealPath()));
        $format = PhrStructuredFormat::detect($contents);
        if ($format === null) {
            return $this->privateJson(['error' => 'unsupported_format'], 422);
        }
        try {
            // All of the file or none of it: an entry that fails partway must not
            // leave the entries before it written, unsummarized.
            $result = DB::transaction(fn () => $format === PhrStructuredFormat::CCDA
                ? $this->ccda->importXml($contents, $patientModel, $actor)
                : $this->fhir->importBundle((array) PhrStructuredFormat::fhirBundle($contents), $patientModel, $actor));
        } catch (Throwable $failure) {
            if (! $failure instanceof InvalidArgumentException) {
                Log::warning('Structured file import failed.', ['exception' => $failure::class]);
            }

            return $this->privateJson(['error' => 'unreadable'], 422);
        }

        return $this->privateJson(['format' => $format, 'result' => $result->toArray()]);
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = response()->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
