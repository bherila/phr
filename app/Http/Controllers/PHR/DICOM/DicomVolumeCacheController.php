<?php

namespace App\Http\Controllers\PHR\DICOM;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DICOM\StoreDicomVolumeCacheRequest;
use App\Models\PhrDicomSeries;
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
        $resolvedSeries = $this->resolveSeries($request, $patient, $series);
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

    private function resolveSeries(Request $request, int $patient, int $series): PhrDicomSeries
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        return PhrDicomSeries::query()
            ->where('patient_id', (int) $resolvedPatient->id)
            ->with(['instances'])
            ->findOrFail($series);
    }
}
