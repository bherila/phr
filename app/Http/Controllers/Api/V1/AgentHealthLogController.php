<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\AgentApi\AgentAppendResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\AppendAgentHealthLogEntryRequest;
use App\Http\Requests\AgentApi\StoreAgentHealthLogRequest;
use App\Http\Resources\PHR\HealthLogEntryResource;
use App\Http\Resources\PHR\HealthLogResource;
use App\Models\PhrHealthLogEntry;
use App\Services\AgentApi\AgentHealthLogMutationService;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\HealthLog\PhrHealthLogDao;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiUpdateWindow;
use App\Support\AgentApi\AgentMutationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentHealthLogController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrHealthLogDao $healthLogs,
        private AgentHealthLogMutationService $mutations,
    ) {}

    public function store(StoreAgentHealthLogRequest $request, int $patient): JsonResponse
    {
        $actorId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $actorId);
        $result = $this->mutations->createLog(
            $resolvedPatient,
            $actorId,
            AgentApiClientIdentity::fromRequest($request),
            $request->command(),
        );

        return response()->json(
            AgentMutationResponse::payload(
                $request,
                'health-log',
                $resolvedPatient->id,
                $result->outcome,
                AgentApiScopes::CLINICAL_READ,
                $result->record,
                fn (): array => (new HealthLogResource($result->record))->resolve($request),
            ),
            $result->outcome === AgentAppendResult::CREATED ? 201 : 200,
        );
    }

    public function entries(Request $request, int $patient, int $healthLog): JsonResponse
    {
        $validated = $request->validate($this->entryListRules());
        $actorId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->accessiblePatientWithCurrentGrant($patient, $actorId);
        $resolvedLog = $this->healthLogs->log($resolvedPatient->id, $healthLog);
        $query = $this->healthLogs->entriesQuery($resolvedPatient->id, $resolvedLog->id);
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('patient_id'));
        if (isset($validated['occurred_after'])) {
            $query->where('occurred_at', '>=', $validated['occurred_after']);
        }
        if (isset($validated['occurred_before'])) {
            $query->where('occurred_at', '<=', $validated['occurred_before']);
        }

        $limit = (int) ($validated['limit'] ?? 25);
        $cursor = isset($validated['cursor']) ? (string) $validated['cursor'] : null;
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode($cursor),
        );

        return response()->json([
            'resource_type' => 'health-log-entry',
            'patient_id' => $resolvedPatient->id,
            'health_log_id' => $resolvedLog->id,
            'data' => $page->getCollection()
                ->map(fn (PhrHealthLogEntry $entry): array => (new HealthLogEntryResource($entry))->resolve($request))
                ->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    public function entry(Request $request, int $patient, int $healthLog, int $entry): JsonResponse
    {
        $actorId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->accessiblePatientWithCurrentGrant($patient, $actorId);
        $resolvedLog = $this->healthLogs->log($resolvedPatient->id, $healthLog);
        $resolvedEntry = $this->healthLogs->entry($resolvedPatient->id, $resolvedLog->id, $entry);

        return response()->json([
            'resource_type' => 'health-log-entry',
            'patient_id' => $resolvedPatient->id,
            'health_log_id' => $resolvedLog->id,
            'data' => (new HealthLogEntryResource($resolvedEntry))->resolve($request),
        ]);
    }

    public function append(
        AppendAgentHealthLogEntryRequest $request,
        int $patient,
        int $healthLog,
    ): JsonResponse {
        $actorId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $actorId);
        $resolvedLog = $this->healthLogs->log($resolvedPatient->id, $healthLog);
        $result = $this->mutations->appendEntry(
            $resolvedPatient,
            $resolvedLog,
            $actorId,
            AgentApiClientIdentity::fromRequest($request),
            $request->command(),
        );

        return response()->json(
            AgentMutationResponse::payload(
                $request,
                'health-log-entry',
                $resolvedPatient->id,
                $result->outcome,
                AgentApiScopes::CLINICAL_READ,
                $result->record,
                fn (): array => (new HealthLogEntryResource($result->record))->resolve($request),
                extra: ['health_log_id' => $resolvedLog->id],
            ),
            $result->outcome === AgentAppendResult::CREATED ? 201 : 200,
        );
    }

    /** @return array<string, mixed> */
    private function entryListRules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'occurred_after' => ['sometimes', 'date'],
            'occurred_before' => ['sometimes', 'date', 'after_or_equal:occurred_after'],
        ];
    }
}
