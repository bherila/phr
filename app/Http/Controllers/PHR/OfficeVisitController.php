<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\ReviewClinicalRecordRequest;
use App\Http\Requests\PHR\StoreOfficeVisitRequest;
use App\Http\Resources\PHR\OfficeVisitResource;
use App\Models\PhrDicomStudy;
use App\Models\PhrOfficeVisit;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        return $this->showClinicalResource($request, $patient, $visit);
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

    public function review(ReviewClinicalRecordRequest $request, int $patient, int $visit): JsonResponse
    {
        return $this->reviewClinicalResource($request, $patient, $visit);
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
     * @param  Builder<PhrOfficeVisit>  $query
     * @return Builder<PhrOfficeVisit>
     */
    protected function showQuery(Builder $query): Builder
    {
        // The records made during the visit (#411), a bounded preview of each kind.
        $linked = static fn (BelongsToMany $relation) => $relation
            ->whereNull($relation->getRelated()->qualifyColumn('retracted_at'))
            ->orderBy($relation->getRelated()->qualifyColumn('id'))
            ->limit(OfficeVisitResource::LINKED_PREVIEW_LIMIT);

        return $query->with([
            'eobs.lines',
            'imagingStudies',
            'participants',
            'linkedDocuments' => $linked,
            'linkedLabResults' => $linked,
            'linkedVitals' => $linked,
            'linkedMedications' => $linked,
            'linkedProcedures' => $linked,
            // What happened to each home medication during the stay (#414), with the medications named.
            'medicationTransitions' => static fn (HasMany $transitions) => $transitions
                ->whereNull('retracted_at')
                ->where('review_status', '!=', PhrReviewStatus::REJECTED)
                ->with(['homeMedication:id,name,dose,dose_unit,route,frequency,category', 'orderMedication:id,name,dose,dose_unit,route,frequency,category'])
                ->orderBy('id')
                ->limit(OfficeVisitResource::LINKED_PREVIEW_LIMIT),
        ]);
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
            ->with('participants')
            ->orderByDesc('visit_date')
            ->orderByDesc('id');
    }
}
