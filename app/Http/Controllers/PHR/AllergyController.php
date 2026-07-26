<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreAllergyRequest;
use App\Http\Resources\PHR\AllergyResource;
use App\Models\PhrAllergy;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AllergyController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrAllergy> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreAllergyRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $allergy): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $allergy);
    }

    public function update(StoreAllergyRequest $request, int $patient, int $allergy): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $allergy);
    }

    public function destroy(Request $request, int $patient, int $allergy): Response
    {
        return $this->destroyClinicalResource($request, $patient, $allergy);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrAllergy>
     */
    protected function modelClass(): string
    {
        return PhrAllergy::class;
    }

    protected function resourceClass(): string
    {
        return AllergyResource::class;
    }

    protected function collectionKey(): string
    {
        return 'allergies';
    }

    protected function resourceKey(): string
    {
        return 'allergy';
    }

    /**
     * @param  Builder<PhrAllergy>  $query
     * @return Builder<PhrAllergy>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderBy('clinical_status')
            ->orderBy('substance');
    }
}
