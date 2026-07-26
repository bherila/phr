<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreImmunizationRequest;
use App\Http\Resources\PHR\ImmunizationResource;
use App\Models\PhrImmunization;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ImmunizationController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrImmunization> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreImmunizationRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $immunization): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $immunization);
    }

    public function update(StoreImmunizationRequest $request, int $patient, int $immunization): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $immunization);
    }

    public function destroy(Request $request, int $patient, int $immunization): Response
    {
        return $this->destroyClinicalResource($request, $patient, $immunization);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrImmunization>
     */
    protected function modelClass(): string
    {
        return PhrImmunization::class;
    }

    protected function resourceClass(): string
    {
        return ImmunizationResource::class;
    }

    protected function collectionKey(): string
    {
        return 'immunizations';
    }

    protected function resourceKey(): string
    {
        return 'immunization';
    }

    /**
     * @param  Builder<PhrImmunization>  $query
     * @return Builder<PhrImmunization>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderByDesc('administered_on')
            ->orderByDesc('id');
    }
}
