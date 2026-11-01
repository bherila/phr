<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\ResolveFillDuplicateRequest;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Fills\MedicationFillDuplicateService;
use App\Support\AgentApi\AgentApiClientIdentity;
use Illuminate\Http\JsonResponse;

/** Answers a possible-duplicate flag on a fill this connection wrote. */
final class AgentFillDuplicateController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private MedicationFillDuplicateService $duplicates,
    ) {}

    public function resolve(ResolveFillDuplicateRequest $request, int $patient, int $fill): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $candidate = $request->validated('candidate_fill_id');
        $result = $this->duplicates->resolve(
            $resolved,
            $fill,
            (string) $request->validated('action'),
            (string) $request->validated('expected_version'),
            $candidate !== null ? (int) $candidate : null,
            AgentApiClientIdentity::fromRequest($request)->importSource(),
        );
        unset($result['fill']);

        return response()->json([
            'resource_type' => 'medication_fill_duplicate_resolution',
            'patient_id' => (int) $resolved->id,
            'outcome' => $result['outcome'],
            'data' => [
                'fill_id' => $result['fill_id'],
                'action' => $result['action'],
                'merged_into_fill_id' => $result['merged_into_fill_id'],
                'moved' => $result['moved'],
                'version' => $result['version'],
            ],
        ]);
    }
}
