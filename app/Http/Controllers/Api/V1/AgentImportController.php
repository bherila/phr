<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\PHR\ImportJobMutationResult;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\ReviewAgentImportRequest;
use App\Http\Requests\AgentApi\StoreAgentImportRequest;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrDocumentProcessingService;
use App\Services\PHR\Import\PhrImportJobDao;
use App\Services\PHR\Import\PhrImportReviewService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AgentImportController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $access,
        private PhrImportJobDao $jobs,
        private PhrDocumentProcessingService $processing,
        private PhrImportReviewService $reviews,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'status' => ['sometimes', Rule::in(GenAiImportJob::VALID_STATUSES)],
        ]);
        $resolved = $this->readablePatient($request, $patient);
        $limit = (int) ($validated['limit'] ?? 25);
        $query = $this->jobs->forPatient($resolved)
            ->with('sourceDocument:id,patient_id,genai_job_id')
            ->withCount([
                'results',
                'results as pending_results_count' => fn (Builder $results): Builder => $results->where('status', 'pending_review'),
            ]);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null),
        );

        return response()->json([
            'resource_type' => 'import_job',
            'patient_id' => $resolved->id,
            'data' => $page->getCollection()->map(fn (GenAiImportJob $job): array => $this->jobPayload($job))->values(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $page->hasMorePages(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    public function show(Request $request, int $patient, int $import): JsonResponse
    {
        $resolved = $this->readablePatient($request, $patient);
        $job = $this->jobs->find($resolved, $import);
        $job->load([
            'sourceDocument:id,patient_id,genai_job_id',
            'results' => fn ($results) => $results->orderBy('result_index')->orderBy('id'),
        ])->loadCount([
            'results',
            'results as pending_results_count' => fn (Builder $results): Builder => $results->where('status', 'pending_review'),
        ]);

        return response()->json([
            'resource_type' => 'import_job',
            'patient_id' => $resolved->id,
            'data' => [
                ...$this->jobPayload($job),
                'results' => $job->results->map(fn (GenAiImportResult $result): array => $this->resultPayload($result))->values(),
            ],
        ]);
    }

    public function store(StoreAgentImportRequest $request, int $patient): JsonResponse
    {
        $resolved = $this->writablePatient($request, $patient);
        $result = $this->processing->create(
            $resolved,
            (int) $request->user('api')?->id,
            (int) $request->validated('document_id'),
        );

        return response()->json($this->jobMutationPayload($resolved, $result),
            $result->outcome === ImportJobMutationResult::CREATED ? 202 : 200);
    }

    public function retry(Request $request, int $patient, int $import): JsonResponse
    {
        $resolved = $this->writablePatient($request, $patient);
        $result = $this->processing->retry($resolved, $import);

        return response()->json(
            $this->jobMutationPayload($resolved, $result),
            $result->outcome === ImportJobMutationResult::RETRIED ? 202 : 200,
        );
    }

    public function review(ReviewAgentImportRequest $request, int $patient, int $import, int $result): JsonResponse
    {
        $resolved = $this->writablePatient($request, $patient);
        $job = $this->jobs->find($resolved, $import);
        $this->jobs->result($job, $result);
        $review = $request->validated('action') === 'accept'
            ? $this->reviews->accept(
                $resolved,
                (int) $request->user('api')?->id,
                $import,
                $result,
                $request->payload(),
            )
            : $this->reviews->reject($resolved, $import, $result);

        $payload = [
            'resource_type' => 'import_result',
            'patient_id' => $resolved->id,
            'job_id' => $job->id,
            'outcome' => $review->outcome,
            'import' => $review->import->toArray(),
            'data' => $request->user('api')?->tokenCan(AgentApiScopes::IMPORTS_READ)
                ? $this->resultPayload($review->result)
                : $this->resultReceipt($review->result),
        ];

        return response()->json($payload);
    }

    private function readablePatient(Request $request, int $patient): PhrPatient
    {
        return $this->access->accessiblePatientWithCurrentGrant(
            $patient,
            (int) $request->user('api')?->id,
        );
    }

    private function writablePatient(Request $request, int $patient): PhrPatient
    {
        return $this->access->writablePatient($patient, (int) $request->user('api')?->id);
    }

    /** @return array<string, mixed> */
    private function jobPayload(GenAiImportJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'document_id' => $job->sourceDocument?->id,
            'job_type' => $job->job_type,
            'status' => $job->status,
            'retry_count' => $job->retry_count,
            'can_retry' => $job->canRetry(),
            'result_count' => (int) ($job->results_count ?? 0),
            'pending_result_count' => (int) ($job->pending_results_count ?? 0),
            'processing_tier' => $job->processing_tier,
            'scheduled_for' => $job->scheduled_for?->toDateString(),
            'parsed_at' => $job->parsed_at?->toIso8601String(),
            'failure_code' => $job->status === 'failed' ? 'processing_failed' : null,
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function resultPayload(GenAiImportResult $result): array
    {
        $data = $result->getResultArray();

        return [
            ...$this->resultReceipt($result),
            'result_index' => $result->result_index,
            'produced_by' => $result->produced_by,
            'data' => $data === [] ? (object) [] : $data,
            'imported_at' => $result->imported_at?->toIso8601String(),
            'created_at' => $result->created_at?->toIso8601String(),
            'updated_at' => $result->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{id: int, status: string} */
    private function resultReceipt(GenAiImportResult $result): array
    {
        return ['id' => (int) $result->id, 'status' => (string) $result->status];
    }

    /** @return array<string, mixed> */
    private function jobMutationPayload(PhrPatient $patient, ImportJobMutationResult $result): array
    {
        return [
            'resource_type' => 'import_job',
            'patient_id' => $patient->id,
            'outcome' => $result->outcome,
            'data' => [
                'id' => (int) $result->job->id,
                'document_id' => $result->documentId,
                'status' => $result->job->status,
            ],
        ];
    }
}
