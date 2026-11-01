<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\PHR\ImportJobMutationResult;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\ReviewAgentImportRequest;
use App\Http\Requests\AgentApi\StoreAgentImportRequest;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrDocumentProcessingService;
use App\Services\PHR\Import\PhrImportJobDao;
use App\Services\PHR\Import\PhrImportJobPresenter;
use App\Services\PHR\Import\PhrImportResult;
use App\Services\PHR\Import\PhrImportReviewService;
use App\Services\PHR\Import\PhrStructuredDocumentImport;
use App\Services\PHR\Import\PhrStructuredDocumentImporter;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
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
        private PhrStructuredDocumentImporter $structured,
        private PhrImportJobPresenter $presenter,
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
            ->with('sourceDocument:id,patient_id,genai_job_id,deleted_at')
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
            'data' => $page->getCollection()->map(fn (GenAiImportJob $job): array => $this->presenter->job($job))->values(),
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
            'sourceDocument:id,patient_id,genai_job_id,deleted_at',
            'results' => fn ($results) => $results->orderBy('result_index')->orderBy('id'),
        ])->loadCount([
            'results',
            'results as pending_results_count' => fn (Builder $results): Builder => $results->where('status', 'pending_review'),
        ]);

        return response()->json([
            'resource_type' => 'import_job',
            'patient_id' => $resolved->id,
            'data' => [
                ...$this->presenter->job($job),
                'results' => $job->results->map(fn (GenAiImportResult $result): array => $this->presenter->result($result))->values(),
            ],
        ]);
    }

    /**
     * A document PHR's own parsers read (C-CDA, FHIR, or a MyChart or EHI export
     * ZIP) is imported by them, as in the browser. Any other document is not queued
     * for AI: the connected client has its own AI and extracts the records itself,
     * so the answer names the write tools to use (client_extraction_required). A
     * document the person already sent to AI from the browser keeps that import, and
     * a repeat returns it as it stands. Browser uploads and the browser's "send to
     * AI" do not come through here.
     */
    public function store(StoreAgentImportRequest $request, int $patient): JsonResponse
    {
        $resolved = $this->writablePatient($request, $patient);
        $actor = (int) $request->user('api')?->id;
        $document = PhrDocument::query()->where('patient_id', $resolved->id)->findOrFail((int) $request->validated('document_id'));
        if ($document->genai_job_id === null) {
            $structured = $this->structured->import($resolved, $actor, $document);
            if ($structured !== null) {
                return response()->json(
                    $this->structuredPayload($resolved, $document, $structured),
                    $structured->outcome === PhrStructuredDocumentImport::QUEUED ? 202 : 200,
                );
            }

            return response()->json($this->clientExtractionPayload($resolved, $document));
        }

        $result = $this->processing->create($resolved, $actor, (int) $document->id);

        return response()->json($this->jobMutationPayload($resolved, $result), $result->wasQueued() ? 202 : 200);
    }

    public function retry(Request $request, int $patient, int $import): JsonResponse
    {
        $resolved = $this->writablePatient($request, $patient);
        $result = $this->processing->retry($resolved, (int) $request->user('api')?->id, $import);

        return response()->json(
            $this->jobMutationPayload($resolved, $result),
            $result->wasQueued() ? 202 : 200,
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
            'import' => $this->importCounts($review->import),
            'data' => $request->user('api')?->tokenCan(AgentApiScopes::IMPORTS_READ)
                ? $this->presenter->result($review->result)
                : $this->presenter->resultReceipt($review->result),
        ];

        return response()->json($payload);
    }

    /**
     * Counts only, as an explicit allow-list.
     *
     * PhrImportResult also carries free-text importer warnings -- the EOB
     * importers put claim numbers and parser exception messages in there -- and
     * a failure must reach an agent as a stable code, never as provider text.
     * The review path never populates them today, so this is a boundary rather
     * than a fix; an allow-list keeps a field added to the result later from
     * crossing by default.
     *
     * @return array<string, int>
     */
    private function importCounts(PhrImportResult $import): array
    {
        $counts = $import->toArray();

        return [
            'created' => (int) $counts['created'],
            'updated' => (int) $counts['updated'],
            'unchanged' => (int) $counts['unchanged'],
            'skipped' => (int) $counts['skipped'],
            'documents' => (int) $counts['documents'],
        ];
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
    private function structuredPayload(PhrPatient $patient, PhrDocument $document, PhrStructuredDocumentImport $structured): array
    {
        $upload = $structured->upload;

        return [
            'resource_type' => 'structured_import',
            'patient_id' => $patient->id,
            'outcome' => $structured->outcome,
            'data' => [
                'document_id' => (int) $document->id,
                'format' => $structured->format,
                'import' => $structured->result === null ? null : $this->importCounts($structured->result),
                'upload' => $upload === null ? null : [
                    'id' => (int) $upload->id,
                    'status' => $upload->status,
                    'format' => $upload->format,
                    'failure_reason' => $upload->failure_reason,
                    'package_id' => $upload->package_id,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function clientExtractionPayload(PhrPatient $patient, PhrDocument $document): array
    {
        return [
            'resource_type' => 'client_extraction',
            'patient_id' => $patient->id,
            'outcome' => 'client_extraction_required',
            'data' => [
                'document_id' => (int) $document->id,
                'write_tools' => [...AgentClinicalResourceCatalog::upsertOperationIds(), 'eobs.upsert'],
                'next_step' => 'PHR does not extract this document for a connected client. Read it (documents.download_access.create) and write what it shows with the upsert tools, passing this document as source_document_id. A prescription label, receipt or statement is medication_fills.upsert; an EOB is eobs.import first, then eobs.upsert if that reports unrecognised_format.',
            ],
        ];
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
