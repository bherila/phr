<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Review\PhrReviewQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's review queue for the browser: counts for the sidebar and the
 * pending items for the Review page. Read-only and readable access, like every
 * other patient-scoped list; `can_manage` says whether this person may decide.
 * Decisions go to the routes that own them (a section's review route, the AI
 * import accept/reject routes, the EHI proposal review route).
 */
final class PhrReviewController extends Controller
{
    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly PhrReviewQueueService $queue,
    ) {}

    public function summary(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $patientModel = $this->access->accessiblePatient($patient, $userId);

        return $this->privateJson([
            'summary' => $this->queue->summary($patientModel),
            'can_manage' => $this->access->canWrite($patientModel, $userId),
        ]);
    }

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $patientModel = $this->access->accessiblePatient($patient, $userId);

        return $this->privateJson([
            'summary' => $this->queue->summary($patientModel),
            'groups' => $this->queue->groups($patientModel),
            'item_limit' => PhrReviewQueueService::ITEM_LIMIT,
            'can_manage' => $this->access->canWrite($patientModel, $userId),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload): JsonResponse
    {
        $response = response()->json($payload);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
