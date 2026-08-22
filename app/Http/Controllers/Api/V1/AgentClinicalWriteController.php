<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\UpdateClinicalRecordRequest;
use App\Http\Requests\AgentApi\UpsertClinicalRecordRequest;
use App\Services\AgentApi\AgentClinicalRecordUpdateService;
use App\Services\AgentApi\AgentClinicalUpsertService;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentMutationResponse;
use Illuminate\Http\JsonResponse;

final class AgentClinicalWriteController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private AgentClinicalUpsertService $upserts,
        private AgentClinicalRecordUpdateService $updates,
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

        return response()->json(
            AgentMutationResponse::payload(
                $request,
                $resource,
                $resolvedPatient->id,
                $result->outcome,
                AgentApiScopes::CLINICAL_READ,
                $result->record,
                fn (): array => (new $resourceClass($result->record))->resolve($request),
                $result->version,
            ),
            $result->outcome === ClinicalUpsertResult::CREATED ? 201 : 200,
        );
    }

    public function update(UpdateClinicalRecordRequest $request, int $patient, string $resource, int $record): JsonResponse
    {
        $userId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $result = $this->updates->update($resolvedPatient, $record, $request->updateData());
        $definition = AgentClinicalResourceCatalog::definition($resource) ?? abort(404);
        $resourceClass = $definition['resource'];

        // This route already requires clinical:read, so the full resource is
        // always returned. It shares the presenter for response consistency.
        return response()->json(AgentMutationResponse::payload(
            $request,
            $resource,
            $resolvedPatient->id,
            $result->outcome,
            AgentApiScopes::CLINICAL_READ,
            $result->record,
            fn (): array => (new $resourceClass($result->record))->resolve($request),
            $result->version,
        ));
    }
}
