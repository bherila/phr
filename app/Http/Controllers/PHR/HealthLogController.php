<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StoreHealthLogRequest;
use App\Http\Requests\PHR\UpdateHealthLogRequest;
use App\Http\Resources\PHR\HealthLogResource;
use App\Models\PhrHealthLog;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\HealthLog\PhrHealthLogDao;
use App\Services\PHR\HealthLog\PhrHealthLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HealthLogController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrHealthLogService $healthLogService,
        private PhrHealthLogDao $healthLogs,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $healthLogs = $this->healthLogs->logs($resolvedPatient->id)
            ->map(fn (PhrHealthLog $healthLog): array => $this->payload($healthLog))
            ->values();

        return response()->json([
            'health_logs' => $healthLogs,
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function store(StoreHealthLogRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $healthLog = $this->healthLogService->createLog($resolvedPatient, $userId, $request->validated());

        return response()->json(['health_log' => $this->payload($healthLog)], 201);
    }

    public function show(Request $request, int $patient, int $healthLog): JsonResponse
    {
        $resolvedPatient = $this->accessiblePatient($request, $patient);
        $resolvedLog = $this->healthLogs->log($resolvedPatient->id, $healthLog, true);

        return response()->json(['health_log' => $this->payload($resolvedLog)]);
    }

    public function update(UpdateHealthLogRequest $request, int $patient, int $healthLog): JsonResponse
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $resolvedLog = $this->resolveHealthLog($resolvedPatient, $healthLog);
        $updated = $this->healthLogService->updateLog($resolvedLog, $request->validated());

        return response()->json(['health_log' => $this->payload($updated)]);
    }

    public function destroy(Request $request, int $patient, int $healthLog): Response
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $this->resolveHealthLog($resolvedPatient, $healthLog)->delete();

        return response()->noContent();
    }

    private function accessiblePatient(Request $request, int $patient): PhrPatient
    {
        return $this->accessService->accessiblePatient($patient, (int) $request->user()?->id);
    }

    private function writablePatient(Request $request, int $patient): PhrPatient
    {
        return $this->accessService->writablePatient($patient, (int) $request->user()?->id);
    }

    private function resolveHealthLog(PhrPatient $patient, int $healthLog): PhrHealthLog
    {
        return $this->healthLogs->log($patient->id, $healthLog);
    }

    /** @return array<string, mixed> */
    private function payload(PhrHealthLog $healthLog): array
    {
        return (new HealthLogResource($healthLog))->resolve();
    }
}
