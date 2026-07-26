<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreMedicationRequest;
use App\Http\Resources\PHR\MedicationResource;
use App\Models\PhrMedication;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MedicationController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrMedication> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreMedicationRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $medication): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $medication);
    }

    public function update(StoreMedicationRequest $request, int $patient, int $medication): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $medication);
    }

    public function destroy(Request $request, int $patient, int $medication): Response
    {
        return $this->destroyClinicalResource($request, $patient, $medication);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrMedication>
     */
    protected function modelClass(): string
    {
        return PhrMedication::class;
    }

    protected function resourceClass(): string
    {
        return MedicationResource::class;
    }

    protected function collectionKey(): string
    {
        return 'medications';
    }

    protected function resourceKey(): string
    {
        return 'medication';
    }

    /**
     * @param  Builder<PhrMedication>  $query
     * @return Builder<PhrMedication>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderBy('status')
            ->orderByDesc('started_on')
            ->orderByDesc('id');
    }
}
