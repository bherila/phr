<?php

namespace App\Http\Controllers\PHR\DICOM;

use App\Http\Controllers\Controller;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DICOM\DicomStudyPresenter;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use App\Services\PHR\DICOM\VolumeCacheService;
use App\Services\PHR\DICOM\VolumeSeriesInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DicomStudyController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private DicomUploadProcessor $uploadProcessor,
        private DicomStudyPresenter $studyPresenter,
        private VolumeSeriesInspector $volumeSeriesInspector,
        private VolumeCacheService $volumeCacheService,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $studies = $this->studyPresenter->withSummaryMetrics(PhrDicomStudy::query())
            ->forPatient((int) $resolvedPatient->id)
            ->orderByDesc('study_date')
            ->orderByDesc('study_time')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PhrDicomStudy $study): array => $this->studyPresenter->payload($study))
            ->values();

        return response()->json(['studies' => $studies]);
    }

    public function show(Request $request, int $patient, int $study): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $resolvedStudy = $this->studyPresenter->withSummaryMetrics(PhrDicomStudy::query())
            ->forPatient((int) $resolvedPatient->id)
            ->findOrFail($study);

        return response()->json(['study' => $this->studyPresenter->payload($resolvedStudy)]);
    }

    public function viewerJson(Request $request, int $patient, int $study): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $resolvedStudy = PhrDicomStudy::query()
            ->forPatient((int) $resolvedPatient->id)
            ->with(['series.instances.file'])
            ->findOrFail($study);

        return response()->json([
            'studies' => [$this->viewerStudyPayload($resolvedStudy, (int) $resolvedPatient->id)],
        ])->header('Cache-Control', 'no-store');
    }

    public function volumeManifest(Request $request, int $patient, int $series): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $resolvedSeries = PhrDicomSeries::query()
            ->where('patient_id', (int) $resolvedPatient->id)
            ->with(['instances.file'])
            ->findOrFail($series);

        $inspection = $this->volumeSeriesInspector->inspect($resolvedSeries);
        $inspection['instances'] = array_map(function (array $instance) use ($resolvedSeries): array {
            $resolvedInstance = $resolvedSeries->instances->firstWhere('id', $instance['id']);

            return [
                ...$instance,
                'url' => $this->uploadProcessor->instanceDownloadUrl($resolvedInstance, (int) $resolvedSeries->patient_id),
            ];
        }, $inspection['instances']);

        return response()->json([
            'series' => [
                'id' => $resolvedSeries->id,
                'series_instance_uid' => $resolvedSeries->series_instance_uid,
                'modality' => $resolvedSeries->modality,
                'description' => $resolvedSeries->description,
            ],
            ...$inspection,
            'cache' => $this->volumeCacheService->manifestPayload($resolvedSeries, (int) $resolvedPatient->id),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewerStudyPayload(PhrDicomStudy $study, int $patientId): array
    {
        $metadata = $study->metadata_json ?? [];
        $seriesPayloads = $study->series
            ->sortBy(fn (PhrDicomSeries $series): int => $series->series_number ?? $series->id)
            ->map(fn (PhrDicomSeries $series): array => $this->viewerSeriesPayload($series, $patientId, $study->study_instance_uid))
            ->values()
            ->all();

        return [
            'StudyInstanceUID' => $study->study_instance_uid,
            'StudyDate' => $metadata['StudyDate'] ?? $study->study_date?->format('Ymd') ?? '',
            'StudyTime' => $metadata['StudyTime'] ?? $study->study_time ?? '',
            'PatientName' => $metadata['PatientName'] ?? '',
            'PatientID' => $metadata['PatientID'] ?? '',
            'AccessionNumber' => $study->accession_number ?? $metadata['AccessionNumber'] ?? '',
            'PatientAge' => $metadata['PatientAge'] ?? '',
            'PatientSex' => $metadata['PatientSex'] ?? '',
            'StudyDescription' => $study->description ?? $metadata['StudyDescription'] ?? '',
            'series' => $seriesPayloads,
            'NumInstances' => $study->series->sum(fn (PhrDicomSeries $series): int => $series->instances->count()),
            'Modalities' => $study->modalities ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function viewerSeriesPayload(PhrDicomSeries $series, int $patientId, string $studyInstanceUid): array
    {
        $metadata = $series->metadata_json ?? [];

        return [
            'id' => $series->id,
            'SeriesInstanceUID' => $series->series_instance_uid,
            'SeriesNumber' => $series->series_number ?? $metadata['SeriesNumber'] ?? null,
            'Modality' => $series->modality ?? $metadata['Modality'] ?? '',
            'SeriesDescription' => $series->description ?? $metadata['SeriesDescription'] ?? '',
            'instances' => $series->instances
                ->sortBy(fn (PhrDicomInstance $instance): int => $instance->instance_number ?? $instance->id)
                ->map(fn (PhrDicomInstance $instance): array => $this->viewerInstancePayload($instance, $series, $patientId, $studyInstanceUid))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{metadata: array<string, mixed>, url: string}
     */
    private function viewerInstancePayload(PhrDicomInstance $instance, PhrDicomSeries $series, int $patientId, string $studyInstanceUid): array
    {
        $metadata = $instance->metadata_json ?? [];
        $metadata['StudyInstanceUID'] = $studyInstanceUid;
        $metadata['SeriesInstanceUID'] = $series->series_instance_uid;
        $metadata['SOPInstanceUID'] = $instance->sop_instance_uid;
        $metadata['SOPClassUID'] = $instance->sop_class_uid ?? $metadata['SOPClassUID'] ?? null;
        $metadata['Modality'] = $series->modality ?? $metadata['Modality'] ?? null;
        $metadata['InstanceNumber'] = $instance->instance_number ?? $metadata['InstanceNumber'] ?? null;
        $metadata['Rows'] = $instance->rows ?? $metadata['Rows'] ?? null;
        $metadata['Columns'] = $instance->columns ?? $metadata['Columns'] ?? null;
        $metadata['NumberOfFrames'] = $instance->number_of_frames ?? $metadata['NumberOfFrames'] ?? null;

        return [
            'metadata' => array_filter($metadata, fn (mixed $value): bool => $value !== null),
            'url' => 'dicomweb:'.$this->uploadProcessor->instanceDownloadUrl($instance, $patientId),
        ];
    }
}
