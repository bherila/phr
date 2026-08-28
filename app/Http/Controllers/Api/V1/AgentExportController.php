<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrExport;
use App\Models\PhrNativeBackup;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Export\PhrExportService;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiUpdateWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** OAuth adapter over the existing queued export and native-backup services. */
final class AgentExportController extends Controller
{
    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly PhrExportService $exports,
        private readonly PhrNativeBackupService $backups,
    ) {}

    public function exportsIndex(Request $request, int $patient): JsonResponse
    {
        $resolved = $this->ownedPatient($request, $patient);
        [$limit, $cursor, $validated] = $this->pageInput($request);
        $query = PhrExport::query()->where('patient_id', $resolved->id);
        AgentApiUpdateWindow::apply($query, $validated, 'patient_id');
        $page = $query->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', AgentApiCursor::decode($cursor));

        return response()->json([
            'resource_type' => 'export',
            'patient_id' => (int) $resolved->id,
            'data' => $page->getCollection()->map(fn (PhrExport $export): array => $this->exportPayload($export))->values(),
            'pagination' => $this->pagination($page, $limit),
        ]);
    }

    public function exportsStore(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate([
            'formats' => ['sometimes', 'array', 'min:1'],
            'formats.*' => ['string', Rule::in(PhrExportService::FORMATS)],
        ]);
        $resolved = $this->ownedPatient($request, $patient);
        $formats = isset($validated['formats']) && is_array($validated['formats']) ? $validated['formats'] : ['zip'];
        $export = $this->exports->createQueuedExport($resolved, (int) $request->user('api')?->id, $formats)->refresh();

        return response()->json([
            'resource_type' => 'export',
            'patient_id' => (int) $resolved->id,
            'outcome' => 'queued',
            'data' => $this->exportPayload($export),
        ], 202);
    }

    public function backupsIndex(Request $request, int $patient): JsonResponse
    {
        $resolved = $this->ownedPatient($request, $patient);
        [$limit, $cursor, $validated] = $this->pageInput($request);
        $query = PhrNativeBackup::query()->where('patient_id', $resolved->id);
        AgentApiUpdateWindow::apply($query, $validated, 'patient_id');
        $page = $query->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', AgentApiCursor::decode($cursor));

        return response()->json([
            'resource_type' => 'native_backup',
            'patient_id' => (int) $resolved->id,
            'data' => $page->getCollection()->map(fn (PhrNativeBackup $backup): array => $this->backupPayload($backup))->values(),
            'pagination' => $this->pagination($page, $limit),
        ]);
    }

    public function backupsStore(Request $request, int $patient): JsonResponse
    {
        $resolved = $this->ownedPatient($request, $patient);
        $backup = $this->backups->createQueuedBackup($resolved, (int) $request->user('api')?->id)->refresh();

        return response()->json([
            'resource_type' => 'native_backup',
            'patient_id' => (int) $resolved->id,
            'outcome' => 'queued',
            'data' => $this->backupPayload($backup),
        ], 202);
    }

    public function exportDownloadAccess(Request $request, int $patient, int $export): JsonResponse
    {
        $resolved = $this->ownedPatient($request, $patient);
        $item = PhrExport::query()->where('patient_id', $resolved->id)->findOrFail($export);
        abort_unless($this->downloadable($item->status, $item->storage_path, $item->expires_at), 404);

        return response()->json($this->downloadAccessPayload('export', $resolved, $item->id, 'agent-api.v1.exports.file'));
    }

    public function backupDownloadAccess(Request $request, int $patient, int $backup): JsonResponse
    {
        $resolved = $this->ownedPatient($request, $patient);
        $item = PhrNativeBackup::query()->where('patient_id', $resolved->id)->findOrFail($backup);
        abort_unless($this->downloadable($item->status, $item->storage_path, $item->expires_at), 404);

        return response()->json($this->downloadAccessPayload('native_backup', $resolved, $item->id, 'agent-api.v1.native-backups.file'));
    }

    public function exportFile(Request $request, int $patient, int $export): StreamedResponse
    {
        $resolved = $this->ownedPatient($request, $patient);
        $item = PhrExport::query()->where('patient_id', $resolved->id)->findOrFail($export);
        abort_unless($this->downloadable($item->status, $item->storage_path, $item->expires_at), 404);
        abort_unless(Storage::disk($item->storage_disk)->exists((string) $item->storage_path), 404);

        return $this->download($item->storage_disk, (string) $item->storage_path, $item->filename ?? "phr-export-{$item->id}.zip");
    }

    public function backupFile(Request $request, int $patient, int $backup): StreamedResponse
    {
        $resolved = $this->ownedPatient($request, $patient);
        $item = PhrNativeBackup::query()->where('patient_id', $resolved->id)->findOrFail($backup);
        abort_unless($this->downloadable($item->status, $item->storage_path, $item->expires_at), 404);
        abort_unless(Storage::disk($item->storage_disk)->exists((string) $item->storage_path), 404);

        return $this->download($item->storage_disk, (string) $item->storage_path, 'phr-native-v1-backup.zip');
    }

    /** @return array{0: int, 1: string|null, 2: array<string, mixed>} */
    private function pageInput(Request $request): array
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date'],
        ]);

        return [(int) ($validated['limit'] ?? 25), $validated['cursor'] ?? null, $validated];
    }

    private function ownedPatient(Request $request, int $patient): PhrPatient
    {
        return $this->access->ownedPatient($patient, (int) $request->user('api')?->id);
    }

    /** @return array<string, mixed> */
    private function exportPayload(PhrExport $export): array
    {
        return [
            'id' => (int) $export->id,
            'formats' => $export->formats_json ?: [$export->format],
            'status' => $export->status,
            'file_size_bytes' => $export->file_size_bytes,
            'generated_at' => $export->generated_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'created_at' => $export->created_at?->toIso8601String(),
            'updated_at' => $export->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function backupPayload(PhrNativeBackup $backup): array
    {
        return [
            'id' => (int) $backup->id,
            'format' => 'phr-native-v1',
            'schema_version' => (int) $backup->schema_version,
            'status' => $backup->status,
            'file_size_bytes' => $backup->file_size_bytes,
            'generated_at' => $backup->generated_at?->toIso8601String(),
            'expires_at' => $backup->expires_at?->toIso8601String(),
            'created_at' => $backup->created_at?->toIso8601String(),
            'updated_at' => $backup->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function downloadAccessPayload(string $resourceType, PhrPatient $patient, int $id, string $route): array
    {
        $expiresAt = now()->addMinute();

        return [
            'resource_type' => $resourceType,
            'patient_id' => (int) $patient->id,
            'id' => $id,
            'expires_at' => $expiresAt->toIso8601String(),
            'download_url' => URL::temporarySignedRoute($route, $expiresAt, ['patient' => $patient->id, $resourceType === 'export' ? 'export' : 'backup' => $id]),
        ];
    }

    private function downloadable(string $status, ?string $storagePath, mixed $expiresAt): bool
    {
        return $status === PhrExport::STATUS_READY
            && $storagePath !== null
            && ($expiresAt === null || $expiresAt->isFuture());
    }

    private function download(string $disk, string $path, string $filename): StreamedResponse
    {
        $response = Storage::disk($disk)->download($path, $filename);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** @return array{limit: int, has_more: bool, next_cursor: string|null} */
    private function pagination(object $page, int $limit): array
    {
        return ['limit' => $limit, 'has_more' => $page->hasMorePages(), 'next_cursor' => $page->nextCursor()?->encode()];
    }
}
