<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\DeleteAgentRecordRequest;
use App\Http\Requests\AgentApi\RetractClinicalRecordRequest;
use App\Http\Requests\AgentApi\UpdateClinicalRecordRequest;
use App\Http\Requests\AgentApi\UpsertClinicalRecordRequest;
use App\Services\AgentApi\AgentClinicalRecordUpdateService;
use App\Services\AgentApi\AgentClinicalRetractionService;
use App\Services\AgentApi\AgentClinicalUpsertService;
use App\Services\AgentApi\AgentRecordDeletionService;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentEncounterContext;
use App\Support\AgentApi\AgentMutationResponse;
use App\Support\PHR\PhrRecordLifecycle;
use Illuminate\Http\JsonResponse;

final class AgentClinicalWriteController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private AgentClinicalUpsertService $upserts,
        private AgentClinicalRecordUpdateService $updates,
        private AgentClinicalRetractionService $retractions,
        private AgentEncounterContext $encounterContext,
        private AgentRecordDeletionService $deletions,
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
                fn (): array => $this->encounterContext->with((new $resourceClass($result->record))->resolve($request), $resource, $result->record),
                $result->version,
            ),
            $result->outcome === ClinicalUpsertResult::CREATED ? 201 : 200,
        );
    }

    public function retract(RetractClinicalRecordRequest $request, int $patient, string $resource, int $record): JsonResponse
    {
        $userId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $result = $this->retractions->retract(
            $resolvedPatient,
            AgentApiClientIdentity::fromRequest($request),
            $resource,
            $record,
            $request->expectedVersion(),
        );
        $definition = AgentClinicalResourceCatalog::definition($resource) ?? abort(404);
        $resourceClass = $definition['resource'];

        return response()->json([
            ...AgentMutationResponse::payload(
                $request,
                $resource,
                $resolvedPatient->id,
                $result->outcome,
                AgentApiScopes::CLINICAL_READ,
                $result->record,
                fn (): array => $this->encounterContext->with((new $resourceClass($result->record))->resolve($request), $resource, $result->record),
                $result->version,
            ),
            'lifecycle' => PhrRecordLifecycle::of($result->record),
        ]);
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
            fn (): array => $this->encounterContext->with((new $resourceClass($result->record))->resolve($request), $resource, $result->record),
            $result->version,
        ));
    }

    /**
     * The browser's soft delete for a record whoever wrote it. The response is the
     * tombstone changes.list reports from now on, not the record: a deleted record
     * has no read left to match.
     */
    public function delete(DeleteAgentRecordRequest $request, int $patient, string $resource, int $record): JsonResponse
    {
        $userId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $result = $this->deletions->deleteClinical($resolvedPatient, $resource, $record, $request->expectedVersion());

        return response()->json([
            'resource_type' => $resource,
            'patient_id' => $resolvedPatient->id,
            'outcome' => $result->outcome,
            'data' => $result->tombstone(),
        ]);
    }
}
