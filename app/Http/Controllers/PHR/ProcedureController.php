<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PHR\Concerns\HandlesClinicalResourceRequests;
use App\Http\Requests\PHR\StoreProcedureRequest;
use App\Http\Resources\PHR\ProcedureResource;
use App\Models\PhrProcedure;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProcedureController extends Controller
{
    /** @use HandlesClinicalResourceRequests<PhrProcedure> */
    use HandlesClinicalResourceRequests;

    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        return $this->indexClinicalResource($request, $patient);
    }

    public function store(StoreProcedureRequest $request, int $patient): JsonResponse
    {
        return $this->storeClinicalResource($request, $patient);
    }

    public function show(Request $request, int $patient, int $procedure): JsonResponse
    {
        return $this->showClinicalResource($request, $patient, $procedure);
    }

    public function update(StoreProcedureRequest $request, int $patient, int $procedure): JsonResponse
    {
        return $this->updateClinicalResource($request, $patient, $procedure);
    }

    public function destroy(Request $request, int $patient, int $procedure): Response
    {
        return $this->destroyClinicalResource($request, $patient, $procedure);
    }

    protected function accessService(): PhrPatientAccessService
    {
        return $this->accessService;
    }

    /**
     * @return class-string<PhrProcedure>
     */
    protected function modelClass(): string
    {
        return PhrProcedure::class;
    }

    protected function resourceClass(): string
    {
        return ProcedureResource::class;
    }

    protected function collectionKey(): string
    {
        return 'procedures';
    }

    protected function resourceKey(): string
    {
        return 'procedure';
    }

    /**
     * @param  Builder<PhrProcedure>  $query
     * @return Builder<PhrProcedure>
     */
    protected function indexQuery(Builder $query): Builder
    {
        return $query
            ->orderByDesc('performed_at')
            ->orderByDesc('performed_on')
            ->orderByDesc('id');
    }
}
