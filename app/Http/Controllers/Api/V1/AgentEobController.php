<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\DataTransferObjects\AgentApi\EobUpsertResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\ImportEobRequest;
use App\Http\Requests\AgentApi\UpdateEobRequest;
use App\Http\Requests\AgentApi\UpsertEobRequest;
use App\Models\PhrDocument;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Eob\EobWriteService;
use App\Services\PHR\Import\EobDocumentImportService;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentMutationResponse;
use Illuminate\Http\JsonResponse;

/** EOB writes: import a stored document with the server's parsers, or upsert a claim the client read. */
final class AgentEobController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private EobDocumentImportService $imports,
        private EobWriteService $writes,
        private AgentEvidenceController $evidence,
    ) {}

    public function import(ImportEobRequest $request, int $patient): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $document = PhrDocument::query()->where('patient_id', $resolved->id)->findOrFail((int) $request->validated('document_id'));
        $result = $this->imports->import($resolved, $document);

        $recognised = $result['outcome'] !== EobDocumentImportService::UNRECOGNISED;

        return response()->json([
            'resource_type' => 'eob_import',
            'patient_id' => (int) $resolved->id,
            'outcome' => $result['outcome'],
            'data' => [
                'document_id' => (int) $document->id,
                'format' => $result['format'],
                'eobs' => array_map(static fn (array $entry): array => [
                    'id' => (int) $entry['eob']->id,
                    'outcome' => $entry['outcome'],
                    'lines_count' => $entry['eob']->lines()->count(),
                ], $result['eobs']),
                'next_step' => $recognised
                    ? 'List the lines with eob_lines.list; for a pharmacy claim, write one fill per line with medication_fills.upsert, passing the line as eob_line_id.'
                    : 'PHR could not read this document as a Meritain EOB PDF or a Delta Dental claim CSV. Extract the claim yourself and call eobs.upsert with this document as source_document_id.',
            ],
        ], $result['outcome'] === EobDocumentImportService::CREATED ? 201 : 200);
    }

    public function upsert(UpsertEobRequest $request, int $patient): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $result = $this->writes->upsert($resolved, AgentApiClientIdentity::fromRequest($request), $request->claim());

        return $this->respond($request, (int) $resolved->id, $result);
    }

    public function update(UpdateEobRequest $request, int $patient, int $eob): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $result = $this->writes->update(
            $resolved,
            AgentApiClientIdentity::fromRequest($request),
            $eob,
            (string) $request->validated('expected_version'),
            (array) $request->validated('data'),
            $request->validated('source_document_id') !== null ? (int) $request->validated('source_document_id') : null,
        );

        return $this->respond($request, (int) $resolved->id, $result);
    }

    private function respond(UpsertEobRequest|UpdateEobRequest $request, int $patientId, EobUpsertResult $result): JsonResponse
    {
        $result->eob->loadCount('lines');

        return response()->json(
            AgentMutationResponse::payload(
                $request,
                'eob',
                $patientId,
                $result->outcome,
                AgentApiScopes::CLINICAL_READ,
                $result->eob,
                fn (): array => $this->evidence->eobResource($result->eob),
                $result->version,
                [
                    'match' => $result->match,
                    'lines' => $result->lines->map(fn ($line): array => ['id' => (int) $line->id, 'line_number' => (int) $line->line_number])->values()->all(),
                    'fills' => $result->fills,
                ],
            ),
            $result->outcome === ClinicalUpsertResult::CREATED ? 201 : 200,
        );
    }
}
