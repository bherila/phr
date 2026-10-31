<?php

namespace App\Http\Controllers\PHR;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Http\Controllers\Controller;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrDocumentProcessingService;
use App\Services\PHR\Import\PhrImportJobDao;
use App\Services\PHR\Import\PhrImportJobPresenter;
use App\Services\PHR\Import\PhrImportReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI imports on the browser's Imports page (#235, PR 5): a patient's AI import
 * jobs, waiting with the person's agent in the AI Agent Inbox or with
 * server-side AI, and their proposed records to review. Decisions go through
 * the same review service as the agent API's imports.review.
 */
final class PhrAiImportController extends Controller
{
    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly PhrImportJobDao $jobs,
        private readonly PhrImportJobPresenter $presenter,
        private readonly PhrImportReviewService $reviews,
        private readonly PhrDocumentProcessingService $processing,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));
        $jobs = $this->withCounts($this->jobs->forPatient($patientModel))->latest('id')->limit(30)->get();

        return $this->privateJson(['imports' => $jobs->map(fn (GenAiImportJob $job): array => $this->job($job))->values()]);
    }

    public function show(Request $request, int $patient, int $import): JsonResponse
    {
        $patientModel = $this->access->accessiblePatient($patient, $this->actor($request));

        return $this->privateJson(['import' => $this->detail($patientModel, $import)]);
    }

    public function accept(Request $request, int $patient, int $import, int $result): JsonResponse
    {
        $actor = $this->actor($request);
        $patientModel = $this->access->writablePatient($patient, $actor);
        $this->jobs->result($this->jobs->find($patientModel, $import), $result);
        $review = $this->reviews->accept($patientModel, $actor, $import, $result, null);

        return $this->privateJson([
            'outcome' => $review->outcome,
            'result' => $this->presenter->resultReceipt($review->result),
            'import' => $review->import->toArray(),
        ]);
    }

    public function reject(Request $request, int $patient, int $import, int $result): JsonResponse
    {
        $patientModel = $this->access->writablePatient($patient, $this->actor($request));
        $this->jobs->result($this->jobs->find($patientModel, $import), $result);
        $review = $this->reviews->reject($patientModel, $import, $result);

        return $this->privateJson(['outcome' => $review->outcome, 'result' => $this->presenter->resultReceipt($review->result)]);
    }

    public function retry(Request $request, int $patient, int $import): JsonResponse
    {
        $actor = $this->actor($request);
        $patientModel = $this->access->writablePatient($patient, $actor);
        $this->processing->retry($patientModel, $actor, $import);

        return $this->privateJson(['import' => $this->detail($patientModel, $import)], 202);
    }

    /** @return array<string, mixed> */
    private function detail(PhrPatient $patient, int $import): array
    {
        $job = $this->withCounts($this->jobs->forPatient($patient))->findOrFail($import);
        $job->load(['results' => fn ($results) => $results->orderBy('result_index')->orderBy('id')]);

        return [
            ...$this->job($job),
            'results' => $job->results->map(fn (GenAiImportResult $result): array => $this->presenter->result($result))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function job(GenAiImportJob $job): array
    {
        return [
            ...$this->presenter->job($job),
            // Where the work happens: the person's agent (the AI Agent Inbox) or server-side AI.
            'execution_mode' => $job->execution_mode,
            'document_title' => $job->sourceDocument?->title,
            'document_filename' => $job->sourceDocument?->original_filename,
        ];
    }

    /**
     * @param  Builder<GenAiImportJob>  $query
     * @return Builder<GenAiImportJob>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query->with('sourceDocument:id,patient_id,genai_job_id,deleted_at,title,original_filename')->withCount([
            'results',
            'results as pending_results_count' => fn (Builder $results): Builder => $results->where('status', 'pending_review'),
        ]);
    }

    private function actor(Request $request): int
    {
        return (int) $request->user()?->id;
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = response()->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
