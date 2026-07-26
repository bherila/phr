<?php

namespace App\Http\Controllers\PHR\DICOM;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DICOM\StoreDicomVolumeCacheRequest;
use App\Models\PhrDicomSeries;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DICOM\VolumeCacheService;
use App\Services\PHR\DICOM\VolumeSeriesInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DicomVolumeCacheController extends Controller
{
    public function __construct(
        private readonly PhrPatientAccessService $accessService,
        private readonly VolumeSeriesInspector $volumeSeriesInspector,
        private readonly VolumeCacheService $volumeCacheService,
    ) {}

    public function store(StoreDicomVolumeCacheRequest $request, int $patient, int $series): JsonResponse
    {
        $resolvedSeries = $this->resolveWritableSeries($request, $patient, $series);
        $inspection = $this->volumeSeriesInspector->inspect($resolvedSeries);
        if (! $inspection['eligible']) {
            return response()->json(['error' => 'series_not_eligible'], 422);
        }

        $artifact = $this->volumeCacheService->store($resolvedSeries, $request->artifact());

        return response()->json([
            'stored' => true,
            'byte_size' => $artifact->file_size_bytes,
            'pipeline_version' => $request->pipelineVersion(),
        ], 201);
    }

    public function show(Request $request, int $patient, int $series): RedirectResponse|StreamedResponse
    {
        $resolvedSeries = $this->resolveSeries($request, $patient, $series);
        $artifact = $this->volumeCacheService->artifact($resolvedSeries);
        abort_if($artifact === null, 404);

        if ($this->volumeCacheService->usesDirectSignedUrls()) {
            return redirect()
                ->away($this->volumeCacheService->temporaryUrl($artifact))
                ->header('Cache-Control', 'private, no-store');
        }

        $stream = $this->volumeCacheService->disk()->readStream($artifact->r2_key);
        abort_if(! is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Length' => (string) $artifact->file_size_bytes,
            'Content-Disposition' => 'inline; filename="'.$artifact->original_filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Read path: any user the patient is shared with may fetch the artifact.
     */
    private function resolveSeries(Request $request, int $patient, int $series): PhrDicomSeries
    {
        $userId = (int) $request->user()?->id;

        return $this->seriesFor(
            $this->accessService->accessiblePatient($patient, $userId),
            $series,
        );
    }

    /**
     * Write path: populating the cache overwrites bytes every other reader of
     * this patient subsequently downloads and decodes, so it requires write
     * access — a read-only (viewer) grant is not enough.
     */
    private function resolveWritableSeries(Request $request, int $patient, int $series): PhrDicomSeries
    {
        $userId = (int) $request->user()?->id;

        return $this->seriesFor(
            $this->accessService->writablePatient($patient, $userId),
            $series,
        );
    }

    private function seriesFor(PhrPatient $patient, int $series): PhrDicomSeries
    {
        return PhrDicomSeries::query()
            ->where('patient_id', (int) $patient->id)
            ->with(['instances'])
            ->findOrFail($series);
    }
}
