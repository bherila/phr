<?php

namespace App\Http\Controllers\PHR;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StorePhrDocumentRequest;
use App\Http\Requests\PHR\UpdatePhrDocumentRequest;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatientVital;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhrDocumentController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        $query = PhrDocument::query()
            ->where('patient_id', $resolvedPatient->id);

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

        $realPath = $file->getRealPath();
        abort_unless(is_string($realPath), 422, 'The uploaded file could not be read.');

        $hash = hash_file('sha256', $realPath);
        abort_unless(is_string($hash), 422, 'The uploaded file could not be hashed.');

        $originalName = $file->getClientOriginalName() ?: 'document';
        $storagePath = $this->storagePath((int) $resolvedPatient->id, $originalName);
        $stream = fopen($realPath, 'rb');
        abort_unless(is_resource($stream), 422, 'The uploaded file could not be opened.');

        try {
            $stored = Storage::disk('phr_documents')->put($storagePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        abort_unless($stored, 500, 'The uploaded file could not be stored.');

        $byteSize = (int) ($file->getSize() ?: 0);
        $document = PhrDocument::query()->create([
            'patient_id' => $resolvedPatient->id,
            'user_id' => $resolvedPatient->owner_user_id,
            'uploaded_by_user_id' => $userId,
            'title' => $request->validated('title') ?: pathinfo($originalName, PATHINFO_FILENAME),
            'document_type' => $request->validated('document_type'),
            'observed_at' => $request->validated('observed_at'),
            'original_filename' => $originalName,
            'storage_disk' => 'phr_documents',
            'storage_path' => $storagePath,
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
            'byte_size' => $byteSize,
            'file_hash' => $hash,
            'summary' => $request->validated('summary'),
            'source' => 'manual_upload',
            'tags' => $this->cleanTags($request->validated('tags', [])),
            'imported_at' => now(),
        ]);

        return response()->json(['document' => $this->payload($document)], 201);
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
        $validated = $request->validated();

        if (array_key_exists('tags', $validated)) {
            $validated['tags'] = $this->cleanTags($validated['tags'] ?? []);
        }

        $resolvedDocument->update($validated);

        return response()->json(['document' => $this->payload($resolvedDocument->refresh())]);
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
        $resolvedDocument = PhrDocument::query()
            ->where('patient_id', $resolvedPatient->id)
            ->findOrFail($document);

        abort_unless($resolvedDocument->storage_path !== null, 404);
        $sourceDisk = Storage::disk($resolvedDocument->storage_disk);
        abort_unless($sourceDisk->exists($resolvedDocument->storage_path), 404);

        $s3Key = 'genai-import/'.$userId.'/'.Str::uuid().'/'.$this->safeStoredFilename($resolvedDocument->original_filename ?? 'document');
        $stream = $sourceDisk->readStream($resolvedDocument->storage_path);
        abort_unless(is_resource($stream), 404);

        try {
            $stored = Storage::disk('s3')->put($s3Key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        abort_unless($stored, 503, 'GenAI staging storage is not available.');

        $job = GenAiImportJob::query()->create([
            'user_id' => $userId,
            'job_type' => 'phr_document',
            'file_hash' => $resolvedDocument->file_hash ?? hash('sha256', $s3Key),
            'original_filename' => $resolvedDocument->original_filename ?? 'document',
            's3_path' => $s3Key,
            'mime_type' => $resolvedDocument->mime_type,
            'file_size_bytes' => $resolvedDocument->byte_size,
            'context_json' => json_encode([
                'patient_id' => $resolvedPatient->id,
                'document_id' => $resolvedDocument->id,
                'document_type' => $resolvedDocument->document_type,
                'filename_hint' => $resolvedDocument->original_filename,
            ]),
            'status' => 'pending',
        ]);

        $resolvedDocument->update(['genai_job_id' => $job->id]);
        ParseImportJob::dispatch($job->id);

        return response()->json([
            'job_id' => $job->id,
            'status' => $job->status,
            'document' => $this->payload($resolvedDocument->refresh()),
        ], 202);
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

        $disk = Storage::disk($document->storage_disk);
        abort_unless($disk->exists($document->storage_path), 404);

        $stream = $disk->readStream($document->storage_path);
        abort_unless(is_resource($stream), 404);

        $filename = $this->safeDownloadName($document->original_filename ?? ('phr-document-'.$document->id));
        $disposition = $inline ? 'inline' : 'attachment';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
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

    /**
     * @return array<int, string>
     */
    private function cleanTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $trimmed = trim($tag);
            if ($trimmed === '') {
                continue;
            }

            $clean[Str::lower($trimmed)] = $trimmed;
        }

        return array_values($clean);
    }

    private function storagePath(int $patientId, string $filename): string
    {
        return 'phr/documents/patients/'.$patientId.'/'.Str::uuid().'/'.$this->safeStoredFilename($filename);
    }

    private function safeStoredFilename(string $filename): string
    {
        $safeName = Str::of($filename)
            ->replaceMatches('/[^\w.\-]+/', '_')
            ->trim('_')
            ->toString();

        return $safeName !== '' ? $safeName : 'document';
    }

    private function safeDownloadName(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], '', $filename);
    }
}
