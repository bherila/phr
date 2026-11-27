<?php

namespace App\Http\Controllers\PHR;

use App\DataTransferObjects\PHR\DocumentUploadData;
use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\ReviewClinicalRecordRequest;
use App\Http\Requests\PHR\StorePhrDocumentRequest;
use App\Http\Requests\PHR\UpdatePhrDocumentRequest;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Documents\PhrDocumentNotePayload;
use App\Services\PHR\Documents\PhrDocumentRelations;
use App\Services\PHR\Documents\PhrDocumentTextReader;
use App\Services\PHR\Documents\PhrDocumentUploadService;
use App\Services\PHR\Import\PhrDocumentProcessingService;
use App\Services\PHR\Review\ClinicalReviewDecisions;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrClinicalNote;
use App\Support\PHR\PhrDocumentTags;
use App\Support\PHR\PhrDocumentUploadLimits;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhrDocumentController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrDocumentUploadService $documentUploads,
        private PhrDocumentProcessingService $documentProcessing,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        // Each document's link is part of its payload and its version.
        $query = PhrDocument::query()
            ->where('patient_id', $resolvedPatient->id)
            ->with('relatesTo');

        $type = $this->stringQuery($request, 'type');
        if ($type !== null && $type !== 'all') {
            abort_unless(in_array($type, PhrDocument::DOCUMENT_TYPES, true), 422, 'Invalid document type.');
            $query->where('document_type', $type);
        }

        $source = $this->stringQuery($request, 'source');
        if ($source !== null && $source !== 'all') {
            abort_unless(in_array($source, PhrDocument::SOURCES, true), 422, 'Invalid document source.');
            $query->where('source', $source);
        }

        // A rejected document leaves the working list, as a rejected record does.
        // It stays reachable, so a mistaken rejection can be undone, on request.
        if (! $request->boolean('include_rejected')) {
            $query->where('review_status', '!=', PhrReviewStatus::REJECTED);
        }

        $dateFrom = $this->stringQuery($request, 'date_from');
        if ($dateFrom !== null) {
            $query->whereDate('observed_at', '>=', $dateFrom);
        }

        $dateTo = $this->stringQuery($request, 'date_to');
        if ($dateTo !== null) {
            $query->whereDate('observed_at', '<=', $dateTo);
        }

        $documents = $query
            ->orderByRaw('observed_at IS NULL')
            ->orderByDesc('observed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $tag = $this->stringQuery($request, 'tag');
        if ($tag !== null) {
            $needle = Str::lower($tag);
            $documents = $documents
                ->filter(fn (PhrDocument $document): bool => collect($document->tags ?? [])
                    ->contains(fn (string $candidate): bool => Str::lower($candidate) === $needle))
                ->values();
        }

        return response()->json([
            'documents' => $documents
                ->map(fn (PhrDocument $document): array => $this->payload($document))
                ->values(),
            'can_manage' => $this->accessService->canWrite($resolvedPatient, $userId),
        ]);
    }

    public function store(StorePhrDocumentRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422, 'A document file is required.');
        $document = $this->documentUploads->upload(
            $resolvedPatient,
            $userId,
            DocumentUploadData::fromValidated($file, $request->validated()),
        )->document;

        // Read back, so the version handed out is the one a later read reports.
        return response()->json(['document' => $this->payload($document->refresh())], 201);
    }

    public function show(Request $request, int $patient, int $document): JsonResponse
    {
        $resolvedDocument = $this->resolveAccessibleDocument($request, $patient, $document);

        return response()->json(['document' => $this->payload($resolvedDocument)]);
    }

    public function file(Request $request, int $patient, int $document): StreamedResponse
    {
        $resolvedDocument = $this->resolveAccessibleDocument($request, $patient, $document);

        return $this->streamDocument($resolvedDocument, inline: true);
    }

    public function update(UpdatePhrDocumentRequest $request, int $patient, int $document): JsonResponse
    {
        $resolvedDocument = $this->resolveWritableDocument($request, $patient, $document);
        $validated = PhrClinicalNote::normalizedChanges($request->validated());

        if (array_key_exists('tags', $validated)) {
            $validated['tags'] = PhrDocumentTags::normalize($validated['tags'] ?? []);
        }
        $relates = array_key_exists('relates_to', $validated);
        $relatesTo = $validated['relates_to'] ?? null;
        unset($validated['relates_to']);

        DB::transaction(function () use ($resolvedDocument, $validated, $relates, $relatesTo): void {
            $resolvedDocument->fill($validated);
            if ($relates && app(PhrDocumentRelations::class)->set($resolvedDocument, $relatesTo === null ? null : ['document_id' => (int) $relatesTo['document_id'], 'relation' => (string) $relatesTo['relation']]) && ! $resolvedDocument->isDirty()) {
                // The link is the document's: a client syncing on updated_at sees it.
                $resolvedDocument->touch();
            }
            $resolvedDocument->save();
        });

        return response()->json(['document' => $this->payload($resolvedDocument->refresh())]);
    }

    /**
     * Records a reviewer's decision on a document, the only way one leaves
     * `pending_review`. With a version, the decision applies only if the document
     * is unchanged since the reviewer read it (409 otherwise), as for a record.
     */
    public function review(ReviewClinicalRecordRequest $request, int $patient, int $document): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $expected = $request->expectedVersion();
        $reviewed = DB::transaction(function () use ($resolvedPatient, $document, $expected, $request): PhrDocument {
            $locked = PhrDocument::query()
                ->where('patient_id', $resolvedPatient->id)
                ->lockForUpdate()
                ->findOrFail($document);
            app(ClinicalReviewDecisions::class)->reviewLocked($locked, $request->reviewStatus(), $expected);

            return $locked;
        });

        return response()->json(['document' => $this->payload($reviewed->refresh())]);
    }

    public function destroy(Request $request, int $patient, int $document): JsonResponse
    {
        $resolvedDocument = $this->resolveWritableDocument($request, $patient, $document);
        $resolvedDocument->delete();

        return response()->json(null, 204);
    }

    public function process(Request $request, int $patient, int $document): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $result = $this->documentProcessing->create($resolvedPatient, $userId, $document);
        $resolvedDocument = PhrDocument::query()->findOrFail($result->documentId);

        return response()->json([
            'job_id' => $result->job->id,
            'status' => $result->job->status,
            'outcome' => $result->outcome,
            'document' => $this->payload($resolvedDocument->refresh()),
        ], $result->wasQueued() ? 202 : 200);
    }

    public function download(Request $request, PhrDocument $document): StreamedResponse
    {
        $userId = (int) $request->user()?->id;
        $this->accessService->accessiblePatient((int) $document->patient_id, $userId);

        return $this->streamDocument($document, inline: false);
    }

    private function resolveAccessibleDocument(Request $request, int $patient, int $document): PhrDocument
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        return PhrDocument::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($document);
    }

    private function resolveWritableDocument(Request $request, int $patient, int $document): PhrDocument
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        return PhrDocument::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($document);
    }

    private function streamDocument(PhrDocument $document, bool $inline): StreamedResponse
    {
        abort_unless($document->storage_path !== null, 404);

        // Pinned rather than read from the row: document bytes are only ever
        // written to this disk, so honouring a stray `storage_disk` value could
        // only ever point the stream somewhere it should not go.
        abort_unless($document->storage_disk === PhrDocument::STORAGE_DISK, 404);

        $disk = Storage::disk(PhrDocument::STORAGE_DISK);
        abort_unless($disk->exists($document->storage_path), 404);

        $stream = $disk->readStream($document->storage_path);
        abort_unless(is_resource($stream), 404);

        $filename = $this->safeDownloadName($document->original_filename ?? ('phr-document-'.$document->id));
        $disposition = $inline ? 'inline' : 'attachment';
        $contentType = $document->mime_type ?: 'application/octet-stream';
        // Browsers download text/markdown rather than show it, so the preview
        // frame is served it as the plain text it is.
        if ($inline && PhrDocumentTextReader::normalizedType($contentType) === PhrDocumentUploadLimits::MARKDOWN) {
            $contentType = 'text/plain; charset=utf-8';
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $document->byte_size,
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PhrDocument $document): array
    {
        return [
            'id' => $document->id,
            'patient_id' => $document->patient_id,
            'user_id' => $document->user_id,
            'uploaded_by_user_id' => $document->uploaded_by_user_id,
            'genai_job_id' => $document->genai_job_id,
            'title' => $document->title,
            'document_type' => $document->document_type,
            'observed_at' => $document->observed_at?->toDateTimeString(),
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'byte_size' => $document->byte_size,
            'file_hash' => $document->file_hash,
            'summary' => $document->summary,
            'source' => $document->source,
            'tags' => $document->tags ?? [],
            'review_status' => $document->review_status,
            // The opaque version a review decision is conditional on, so a person
            // never approves content that changed after the page loaded.
            'version' => app(AgentClinicalRecordVersion::class)->for($document),
            ...PhrDocumentNotePayload::of($document, static fn (?\DateTimeInterface $moment): ?string => $moment === null ? null : Carbon::instance($moment)->toDateTimeString()),
            'imported_at' => $document->imported_at?->toDateTimeString(),
            'created_at' => $document->created_at?->toDateTimeString(),
            'updated_at' => $document->updated_at?->toDateTimeString(),
            'file_url' => url("/api/phr/patients/{$document->patient_id}/documents/{$document->id}/file"),
            'linked_rows' => $this->linkedRows($document),
        ];
    }

    /**
     * @return array<int, array{type: string, id: int, label: string, href: string}>
     */
    private function linkedRows(PhrDocument $document): array
    {
        $patientId = (int) $document->patient_id;
        $rows = [];

        PhrLabResult::query()
            ->where('source_document_id', $document->id)
            ->limit(5)
            ->get(['id', 'test_name', 'analyte'])
            ->each(function (PhrLabResult $lab) use (&$rows, $patientId): void {
                $label = $lab->analyte ?: ($lab->test_name ?: 'Lab result');
                $rows[] = ['type' => 'lab_result', 'id' => $lab->id, 'label' => $label, 'href' => "/phr/patient/{$patientId}/labs"];
            });

        PhrPatientVital::query()
            ->where('source_document_id', $document->id)
            ->limit(5)
            ->get(['id', 'vital_name'])
            ->each(function (PhrPatientVital $vital) use (&$rows, $patientId): void {
                $rows[] = ['type' => 'vital', 'id' => $vital->id, 'label' => $vital->vital_name ?: 'Vital', 'href' => "/phr/patient/{$patientId}/vitals"];
            });

        PhrOfficeVisit::query()
            ->where('source_document_id', $document->id)
            ->limit(5)
            ->get(['id', 'visit_type', 'provider_name', 'chief_complaint'])
            ->each(function (PhrOfficeVisit $visit) use (&$rows, $patientId): void {
                $label = $visit->chief_complaint ?: ($visit->visit_type ?: ($visit->provider_name ?: 'Office visit'));
                $rows[] = ['type' => 'office_visit', 'id' => $visit->id, 'label' => $label, 'href' => "/phr/patient/{$patientId}/office-visits"];
            });

        return $rows;
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function safeDownloadName(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], '', $filename);
    }
}
