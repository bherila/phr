<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\UpsertClinicalRecordRequest;
use App\Services\AgentApi\AgentClinicalUpsertService;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Http\JsonResponse;

final class AgentClinicalWriteController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private AgentClinicalUpsertService $upserts,
    ) {}

    public function upsert(UpsertClinicalRecordRequest $request, int $patient, string $resource): JsonResponse
    {
        $userId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $result = $this->upserts->upsert(
            $resolvedPatient,
            AgentApiClientIdentity::fromRequest($request),
            $request->upsertData(),
        );
        $definition = AgentClinicalResourceCatalog::definition($resource) ?? abort(404);
        $resourceClass = $definition['resource'];

        return response()->json([
            'resource_type' => $resource,
            'patient_id' => $resolvedPatient->id,
            'outcome' => $result->outcome,
            'version' => $result->version,
            'data' => (new $resourceClass($result->record))->resolve($request),
        ], $result->outcome === ClinicalUpsertResult::CREATED ? 201 : 200);
    }
}
