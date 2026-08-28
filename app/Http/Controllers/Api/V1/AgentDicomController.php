<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrDicomSeries;
use App\Models\PhrDicomStudy;
use App\Services\AgentApi\AgentDicomSeriesPresenter;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DICOM\DicomStudyPresenter;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiUpdateWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bounded DICOM metadata only. Image pixels remain behind explicit file access. */
final class AgentDicomController extends Controller
{
    public function __construct(
        private readonly PhrPatientAccessService $accessService,
        private readonly DicomStudyPresenter $studies,
        private readonly AgentDicomSeriesPresenter $series,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'modality' => ['sometimes', 'string', 'max:16'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ]);
        $patientId = $this->patientId($request, $patient);
        $query = $this->studies->withSummaryMetrics(
            PhrDicomStudy::query()->forPatient($patientId),
        );
        AgentApiUpdateWindow::apply($query, $validated, 'phr_dicom_studies.patient_id');
        if (isset($validated['modality'])) {
            $query->where('modalities', 'like', '%'.strtoupper((string) $validated['modality']).'%');
        }
        if (isset($validated['date_from'])) {
            $query->whereDate('study_date', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('study_date', '<=', $validated['date_to']);
        }
        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null),
        );

        return response()->json([
            'resource_type' => 'dicom_study',
            'patient_id' => $patientId,
            'data' => $page->getCollection()->map($this->studies->payload(...))->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    public function show(Request $request, int $patient, int $study): JsonResponse
    {
        $patientId = $this->patientId($request, $patient);
        $resolved = $this->studies->withSummaryMetrics(PhrDicomStudy::query())
            ->forPatient($patientId)
            ->findOrFail($study);

        return response()->json([
            'resource_type' => 'dicom_study',
            'patient_id' => $patientId,
            'data' => $this->studies->payload($resolved),
        ]);
    }

    public function series(Request $request, int $patient, int $study): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'modality' => ['sometimes', 'string', 'max:16'],
        ]);
        $patientId = $this->patientId($request, $patient);
        PhrDicomStudy::query()->where('patient_id', $patientId)->findOrFail($study);
        $query = PhrDicomSeries::query()
            ->where('patient_id', $patientId)
            ->where('study_id', $study)
            ->withCount('instances');
        AgentApiUpdateWindow::apply($query, $validated, 'phr_dicom_series.patient_id');
        if (isset($validated['modality'])) {
            $query->where('modality', strtoupper((string) $validated['modality']));
        }
        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null),
        );

        return response()->json([
            'resource_type' => 'dicom_series',
            'patient_id' => $patientId,
            'study_id' => $study,
            'data' => $page->getCollection()->map($this->series->payload(...))->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    private function patientId(Request $request, int $patient): int
    {
        return (int) $this->accessService->accessiblePatientWithCurrentGrant(
            $patient,
            (int) $request->user('api')?->id,
        )->id;
    }
}
