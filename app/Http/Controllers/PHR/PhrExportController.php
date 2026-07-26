<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StorePhrExportRequest;
use App\Models\PhrExport;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Export\PhrExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhrExportController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrExportService $exportService,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->ownedPatient($patient, $userId);

        $exports = PhrExport::query()
            ->where('patient_id', $resolvedPatient->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (PhrExport $export): array => $this->payload($export))
            ->values();

        return response()->json(['exports' => $exports]);
    }

    public function store(StorePhrExportRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->ownedPatient($patient, $userId);

        $export = $this->exportService->createQueuedExport($resolvedPatient, $userId, $request->formats())->refresh();

        return response()->json(['export' => $this->payload($export)], 202);
    }

    public function download(Request $request, PhrExport $export): StreamedResponse
    {
        $userId = (int) $request->user()?->id;
        $this->accessService->ownedPatient((int) $export->patient_id, $userId);

        abort_unless($export->status === PhrExport::STATUS_READY && $export->storage_path !== null, 404);
        abort_unless($export->expires_at === null || $export->expires_at->isFuture(), 410);
        abort_unless(Storage::disk($export->storage_disk)->exists($export->storage_path), 404);

        return Storage::disk($export->storage_disk)->download($export->storage_path, $export->filename ?? ('phr-export-'.$export->id.'.zip'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PhrExport $export): array
    {
        return [
            'id' => $export->id,
            'patient_id' => $export->patient_id,
            'formats' => $export->formats_json ?: [$export->format],
            'format' => $export->format,
            'status' => $export->status,
            'filename' => $export->filename,
            'file_size_bytes' => $export->file_size_bytes,
            'error_message' => $export->error_message,
            'generated_at' => $export->generated_at?->toDateTimeString(),
            'expires_at' => $export->expires_at?->toDateTimeString(),
            'created_at' => $export->created_at?->toDateTimeString(),
            'download_url' => $export->status === PhrExport::STATUS_READY
                ? URL::temporarySignedRoute('phr.exports.download', now()->addMinutes(15), ['export' => $export->id])
                : null,
        ];
    }
}
