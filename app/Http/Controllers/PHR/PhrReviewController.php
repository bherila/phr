<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\ReviewClinicalRecordsRequest;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Review\ClinicalReviewDecisions;
use App\Services\PHR\Review\PhrReviewQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's review queue for the browser: counts for the sidebar and the
 * pending items for the Review page. Read-only and readable access, like every
 * other patient-scoped list; `can_manage` says whether this person may decide.
 * Clinical decisions share the section routes' locked review service, in bounded
 * batches. AI and EHI proposals retain their own acceptance routes.
 */
final class PhrReviewController extends Controller
{
    /** The patient list's own bootstrap bound; a larger list shows no counts beyond it. */
    public const int OVERVIEW_MAX_PATIENTS = 100;

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

    public function rejected(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $patientModel = $this->access->accessiblePatient($patient, $userId);

        return $this->privateJson([
            'items' => $this->queue->rejectedMedications($patientModel),
            'can_manage' => $this->access->canWrite($patientModel, $userId),
        ]);
    }

    /** Bounded browser-only decisions; every item's version is required. */
    public function decideRecords(ReviewClinicalRecordsRequest $request, int $patient, ClinicalReviewDecisions $decisions): JsonResponse
    {
        $patientModel = $this->access->writablePatient($patient, (int) $request->user()?->id);

        return $this->privateJson($decisions->batch($patientModel, $request->items(), $request->reviewStatus()));
    }

    /**
     * The patient-selection page's counts across every patient this person can read,
     * resolved through the same readable scope as the patient list.
     */
    public function overview(Request $request): JsonResponse
    {
        $ids = PhrPatient::query()->accessibleBy((int) $request->user()?->id)
            ->orderBy('id')->limit(self::OVERVIEW_MAX_PATIENTS)->toBase()->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->values()->all();

        return $this->privateJson($this->queue->overview($ids));
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
