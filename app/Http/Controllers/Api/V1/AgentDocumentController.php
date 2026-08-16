<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrDocument;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiUpdateWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AgentDocumentController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'document_type' => ['sometimes', Rule::in(PhrDocument::DOCUMENT_TYPES)],
            'source' => ['sometimes', Rule::in(PhrDocument::SOURCES)],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'tag' => ['sometimes', 'string', 'max:100'],
        ]);
        $patientId = $this->patientId($request, $patient);
        $query = PhrDocument::query()->where('patient_id', $patientId)->with('genAiJob:id,status');
        AgentApiUpdateWindow::apply($query, $validated, $query->getModel()->qualifyColumn('patient_id'));
        foreach (['document_type', 'source'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (isset($validated['date_from'])) {
            $query->whereDate('observed_at', '>=', $validated['date_from']);
        }
        if (isset($validated['date_to'])) {
            $query->whereDate('observed_at', '<=', $validated['date_to']);
        }
        if (isset($validated['tag'])) {
            // JSON containment is not portable between the SQLite test suite and
            // MariaDB for case-folded values. Browser writes normalize tags, and
            // this exact JSON predicate remains parameterized and index-bounded by
            // patient before evaluation.
            $query->whereJsonContains('tags', (string) $validated['tag']);
        }
        $limit = (int) ($validated['limit'] ?? 25);
        $page = $query->orderBy('id')->cursorPaginate(
            $limit,
            ['*'],
            'cursor',
            AgentApiCursor::decode(isset($validated['cursor']) ? (string) $validated['cursor'] : null),
        );

        return response()->json([
            'resource_type' => 'document', 'patient_id' => $patientId,
            'data' => $page->getCollection()->map(fn (PhrDocument $document): array => $this->payload($document))->values(),
            'pagination' => ['limit' => $limit, 'has_more' => $page->hasMorePages(), 'next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    public function show(Request $request, int $patient, int $document): JsonResponse
    {
        $resolved = $this->document($request, $patient, $document);

        return response()->json([
            'resource_type' => 'document', 'patient_id' => $resolved->patient_id, 'data' => $this->payload($resolved),
        ]);
    }

    public function createDownloadAccess(Request $request, int $patient, int $document): JsonResponse
    {
        $resolved = $this->document($request, $patient, $document);
        $this->assertStoredFile($resolved);
        $expiresAt = now()->addMinute();

        return response()->json([
            'document_id' => $resolved->id,
            'expires_at' => $expiresAt->toIso8601String(),
            // The signed URL is deliberately not a bearer URL. The file route
            // still requires an OAuth token with documents:read, so either
            // credential captured alone is insufficient to retrieve PHI.
            'download_url' => URL::temporarySignedRoute(
                'agent-api.v1.documents.file',
                $expiresAt,
                ['patient' => $resolved->patient_id, 'document' => $resolved->id],
            ),
        ]);
    }

    public function file(Request $request, int $patient, int $document): StreamedResponse
    {
        $resolved = $this->document($request, $patient, $document);
        $this->assertStoredFile($resolved);
        $stream = Storage::disk(PhrDocument::STORAGE_DISK)->readStream((string) $resolved->storage_path);
        abort_unless(is_resource($stream), 404);
        $filename = str_replace(['"', "\r", "\n"], '', $resolved->original_filename ?? ('phr-document-'.$resolved->id));

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $resolved->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) $resolved->byte_size,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function document(Request $request, int $patient, int $document): PhrDocument
    {
        $patientId = $this->patientId($request, $patient);

        return PhrDocument::query()->where('patient_id', $patientId)->with('genAiJob:id,status')->findOrFail($document);
    }

    private function patientId(Request $request, int $patient): int
    {
        return (int) $this->accessService->accessiblePatientWithCurrentGrant(
            $patient,
            (int) $request->user('api')?->id,
        )->id;
    }

    private function assertStoredFile(PhrDocument $document): void
    {
        abort_unless(
            $document->storage_disk === PhrDocument::STORAGE_DISK
            && $document->storage_path !== null
            && Storage::disk(PhrDocument::STORAGE_DISK)->exists($document->storage_path),
            404,
        );
    }

    /** @return array<string, mixed> */
    private function payload(PhrDocument $document): array
    {
        return [
            'id' => $document->id, 'patient_id' => $document->patient_id,
            'title' => $document->title, 'document_type' => $document->document_type,
            'observed_at' => $document->observed_at?->toIso8601String(),
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type, 'byte_size' => $document->byte_size,
            'summary' => $document->summary, 'source' => $document->source,
            'tags' => $document->tags ?? [], 'import_source' => $document->import_source,
            'external_id' => $document->external_id, 'imported_at' => $document->imported_at?->toIso8601String(),
            'processing_state' => $document->genai_job_id === null
                ? 'not_requested'
                : $document->genAiJob->status,
            'has_file' => $document->storage_path !== null,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
