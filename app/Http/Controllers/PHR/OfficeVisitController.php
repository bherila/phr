<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreOfficeVisitRequest;
use App\Http\Resources\PHR\OfficeVisitResource;
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
        return $this->showClinicalResource($request, $patient, $visit);
    }

    public function update(StoreOfficeVisitRequest $request, int $patient, int $visit): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $visit);
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
