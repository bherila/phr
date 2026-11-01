<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\OpenDocumentUploadRequest;
use App\Models\PhrDocumentUpload;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use App\Support\AgentApi\AgentApiClientIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Signed document uploads: open (OAuth), PUT (signed URL), finalize and cancel (OAuth). */
final class AgentDocumentUploadController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private SignedDocumentUploadService $uploads,
    ) {}

    public function open(OpenDocumentUploadRequest $request, int $patient): JsonResponse
    {
        $actor = (int) $request->user('api')?->id;
        $resolved = $this->accessService->writablePatient($patient, $actor);
        $upload = $this->uploads->open($resolved, $actor, AgentApiClientIdentity::fromRequest($request)->importSource(), $request->declared());

        return response()->json([
            'resource_type' => 'document_upload',
            'patient_id' => (int) $resolved->id,
            'data' => [
                ...$this->payload($upload),
                ...$this->uploads->authorization($upload),
                'method' => 'PUT',
            ],
        ], 201);
    }

    /**
     * The PUT. Reached through the signed URL alone: it stages bytes for one
     * session and creates nothing a client can see until finalize.
     */
    public function receive(Request $request, string $upload): JsonResponse
    {
        $session = $this->uploads->receive($upload, $request);

        return response()->json([
            'resource_type' => 'document_upload',
            'patient_id' => (int) $session->patient_id,
            'data' => $this->payload($session),
        ]);
    }

    public function finalize(Request $request, int $patient, string $upload): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $result = $this->uploads->finalize($resolved, AgentApiClientIdentity::fromRequest($request)->importSource(), $upload);

        return response()->json([
            'resource_type' => 'document_upload',
            'patient_id' => (int) $resolved->id,
            'outcome' => $result['outcome'],
            'data' => [...$this->payload($result['upload']), 'document_id' => (int) $result['document']->id],
        ], $result['outcome'] === 'created' ? 201 : 200);
    }

    public function cancel(Request $request, int $patient, string $upload): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $session = $this->uploads->cancel($resolved, AgentApiClientIdentity::fromRequest($request)->importSource(), $upload);

        return response()->json([
            'resource_type' => 'document_upload',
            'patient_id' => (int) $resolved->id,
            'data' => $this->payload($session),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(PhrDocumentUpload $upload): array
    {
        return [
            'upload_id' => $upload->public_id,
            'status' => $upload->status,
            'kind' => $upload->kind,
            'content_type' => $upload->content_type,
            'byte_size' => $upload->byte_size,
            'expires_at' => $upload->expires_at->toIso8601String(),
        ];
    }
}
