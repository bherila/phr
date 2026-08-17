<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StoreHealthLogEntryRequest;
use App\Http\Requests\PHR\UpdateHealthLogEntryRequest;
use App\Http\Resources\PHR\HealthLogEntryResource;
use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\HealthLog\PhrHealthLogDao;
use App\Services\PHR\HealthLog\PhrHealthLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HealthLogEntryController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrHealthLogService $healthLogService,
        private PhrHealthLogDao $healthLogs,
    ) {}

    public function index(Request $request, int $patient, int $healthLog): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $resolvedLog = $this->resolveHealthLog($resolvedPatient, $healthLog);
        $entries = $this->healthLogs->entries($resolvedPatient->id, $resolvedLog->id)
            ->map(fn (PhrHealthLogEntry $entry): array => $this->payload($entry))
            ->values();

        return response()->json([
            'entries' => $entries,
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function store(StoreHealthLogEntryRequest $request, int $patient, int $healthLog): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $resolvedLog = $this->resolveHealthLog($resolvedPatient, $healthLog);
        $entry = $this->healthLogService->createEntry(
            $resolvedPatient,
            $resolvedLog,
            $userId,
            $request->validatedEntryData(),
        );

        return response()->json(['entry' => $this->payload($entry)], 201);
    }

    public function show(Request $request, int $patient, int $healthLog, int $entry): JsonResponse
    {
        $resolvedPatient = $this->accessiblePatient($request, $patient);
        $resolvedLog = $this->resolveHealthLog($resolvedPatient, $healthLog);
        $resolvedEntry = $this->resolveEntry($resolvedPatient, $resolvedLog, $entry);

        return response()->json(['entry' => $this->payload($resolvedEntry)]);
    }

    public function update(
        UpdateHealthLogEntryRequest $request,
        int $patient,
        int $healthLog,
        int $entry,
    ): JsonResponse {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $resolvedLog = $this->resolveHealthLog($resolvedPatient, $healthLog);
        $resolvedEntry = $this->resolveEntry($resolvedPatient, $resolvedLog, $entry);
        $updated = $this->healthLogService->updateEntry($resolvedEntry, $request->validatedEntryData());

        return response()->json(['entry' => $this->payload($updated)]);
    }

    public function destroy(Request $request, int $patient, int $healthLog, int $entry): Response
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $resolvedLog = $this->resolveHealthLog($resolvedPatient, $healthLog);
        $this->resolveEntry($resolvedPatient, $resolvedLog, $entry)->delete();

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

    private function resolveEntry(PhrPatient $patient, PhrHealthLog $healthLog, int $entry): PhrHealthLogEntry
    {
        return $this->healthLogs->entry($patient->id, $healthLog->id, $entry);
    }

    /** @return array<string, mixed> */
    private function payload(PhrHealthLogEntry $entry): array
    {
        return (new HealthLogEntryResource($entry))->resolve();
    }
}
