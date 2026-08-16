<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\AcceptPhrGenAiResultRequest;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrImportJobDao;
use App\Services\PHR\Import\PhrImportReviewService;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhrGenAiImportController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrImportJobDao $jobs,
        private PhrImportReviewService $reviews,
    ) {}

    public function writablePatients(Request $request): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $patients = $this->accessService
            ->writablePatientsQuery($userId)
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'relationship']);

        return response()->json(['patients' => $patients]);
    }

    public function accept(AcceptPhrGenAiResultRequest $request, int $job, int $result): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $genAiJob = $this->jobs->findById($job);

        abort_unless(PhrStructuredDataImporter::isPhrJobType($genAiJob->job_type), 404);

        $context = $genAiJob->getContextArray();
        $patientId = (int) ($context['patient_id'] ?? 0);
        abort_unless($patientId > 0, 422, 'PHR GenAI job is missing patient context.');

        $patient = $this->accessService->writablePatient($patientId, $userId);
        $this->jobs->result($genAiJob, $result);

        $review = $this->reviews->accept($patient, $userId, $job, $result, $request->payload());

        return response()->json([
            'result' => $review->result,
            'import' => $review->import->toArray(),
            'outcome' => $review->outcome,
        ]);
    }
}
