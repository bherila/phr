<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\ReviewClinicalRecordRequest;
use App\Http\Resources\PHR\MedicationTransitionResource;
use App\Models\PhrMedicationTransition;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A patient's medication transitions in the browser (#414): read, reviewed and deleted
 * like the other clinical records. They are written by agents and imports, which name the
 * stay and the medications; the visit page shows them with its medications.
 */
class MedicationTransitionController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrMedicationTransition> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $transition): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $transition);
    }

    public function destroy(Request $request, int $patient, int $transition): Response
    {
        return $this->destroyClinicalResource($request, $patient, $transition);
    }

    public function review(ReviewClinicalRecordRequest $request, int $patient, int $transition): JsonResponse
    {
        return $this->reviewClinicalResource($request, $patient, $transition);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrMedicationTransition>
     */
    protected function modelClass(): string
    {
        return PhrMedicationTransition::class;
    }

    protected function resourceClass(): string
    {
        return MedicationTransitionResource::class;
    }

    protected function collectionKey(): string
    {
        return 'medication_transitions';
    }

    protected function resourceKey(): string
    {
        return 'medication_transition';
    }

    /**
     * @param  Builder<PhrMedicationTransition>  $query
     * @return Builder<PhrMedicationTransition>
     */
    protected function indexQuery(Builder $query): Builder
    {
        $visit = request()->integer('office_visit_id');

        return $query->when($visit > 0, static fn (Builder $transitions) => $transitions->where('office_visit_id', $visit))->orderBy('id');
    }
}
