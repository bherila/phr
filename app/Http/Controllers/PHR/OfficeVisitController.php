<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreOfficeVisitRequest;
use App\Http\Resources\PHR\OfficeVisitResource;
use App\Models\PhrDicomStudy;
use App\Models\PhrOfficeVisit;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OfficeVisitController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrOfficeVisit> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreOfficeVisitRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $visit): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $resolvedVisit = PhrOfficeVisit::query()
            ->where('patient_id', $resolvedPatient->id)
            ->with(['eobs.lines', 'imagingStudies'])
            ->findOrFail($visit);

        return response()->json([
            'office_visit' => (new OfficeVisitResource($resolvedVisit))->resolve(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function update(StoreOfficeVisitRequest $request, int $patient, int $visit): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $visit);
    }

    public function linkImagingStudy(Request $request, int $patient, int $visit, int $study): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $resolvedVisit = PhrOfficeVisit::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($visit);
        $resolvedStudy = PhrDicomStudy::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($study);

        $resolvedVisit->imagingStudies()->syncWithoutDetaching([
            $resolvedStudy->id => ['patient_id' => $resolvedPatient->id],
        ]);

        return response()->json([
            'imaging_study' => $this->imagingStudyPayload($resolvedStudy),
        ], 201);
    }

    public function unlinkImagingStudy(Request $request, int $patient, int $visit, int $study): Response
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $resolvedVisit = PhrOfficeVisit::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($visit);
        $resolvedStudy = PhrDicomStudy::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($study);

        $resolvedVisit->imagingStudies()->detach($resolvedStudy->id);

        return response()->noContent();
    }

    public function destroy(Request $request, int $patient, int $visit): Response
    {
        return $this->destroyClinicalResource($request, $patient, $visit);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrOfficeVisit>
     */
    protected function modelClass(): string
    {
        return PhrOfficeVisit::class;
    }

    protected function resourceClass(): string
    {
        return OfficeVisitResource::class;
    }

    protected function collectionKey(): string
    {
        return 'office_visits';
    }

    protected function resourceKey(): string
    {
        return 'office_visit';
    }

    /**
     * @return array{id: int, study_date: string|null, description: string|null, modalities: string|null, accession_number: string|null}
     */
    private function imagingStudyPayload(PhrDicomStudy $study): array
    {
        return [
            'id' => $study->id,
            'study_date' => $study->study_date?->toDateString(),
            'description' => $study->description,
            'modalities' => $study->modalities,
            'accession_number' => $study->accession_number,
        ];
    }

    /**
     * @param  Builder<PhrOfficeVisit>  $query
     * @return Builder<PhrOfficeVisit>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderByDesc('visit_date')
            ->orderByDesc('id');
    }
}
