<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreConditionRequest;
use App\Http\Resources\PHR\ConditionResource;
use App\Models\PhrCondition;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConditionController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrCondition> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreConditionRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $condition): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $condition);
    }

    public function update(StoreConditionRequest $request, int $patient, int $condition): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $condition);
    }

    public function destroy(Request $request, int $patient, int $condition): Response
    {
        return $this->destroyClinicalResource($request, $patient, $condition);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrCondition>
     */
    protected function modelClass(): string
    {
        return PhrCondition::class;
    }

    protected function resourceClass(): string
    {
        return ConditionResource::class;
    }

    protected function collectionKey(): string
    {
        return 'conditions';
    }

    protected function resourceKey(): string
    {
        return 'condition';
    }

    /**
     * @param  Builder<PhrCondition>  $query
     * @return Builder<PhrCondition>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderBy('clinical_status')
            ->orderByDesc('onset_date')
            ->orderByDesc('id');
    }
}
