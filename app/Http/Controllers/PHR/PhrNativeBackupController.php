<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Models\PhrNativeBackup;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\NativeBackup\PhrNativeBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhrNativeBackupController extends Controller
{
    public function __construct(
        private readonly PhrPatientAccessService $accessService,
        private readonly PhrNativeBackupService $backupService,
    ) {}

    public function index(Request $request, int $patient): JsonResponse
    {
        $resolvedPatient = $this->accessService->ownedPatient($patient, (int) $request->user()?->id);
        $backups = PhrNativeBackup::query()
            ->where('patient_id', $resolvedPatient->id)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (PhrNativeBackup $backup): array => $this->payload($backup))
            ->values();

        return $this->privateJson(['backups' => $backups]);
    }

    public function store(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->ownedPatient($patient, $userId);
        $backup = $this->backupService->createQueuedBackup($resolvedPatient, $userId)->refresh();

        return $this->privateJson(['backup' => $this->payload($backup)], 202);
    }

    public function download(Request $request, PhrNativeBackup $backup): StreamedResponse
    {
        $this->accessService->ownedPatient($backup->patient_id, (int) $request->user()?->id);
        abort_unless($request->hasValidSignature(), 403);
        abort_unless($backup->status === PhrNativeBackup::STATUS_READY && $backup->storage_path !== null, 404);
        abort_unless($backup->expires_at === null || $backup->expires_at->isFuture(), 410);
        abort_unless(Storage::disk($backup->storage_disk)->exists($backup->storage_path), 404);

        $response = Storage::disk($backup->storage_disk)->download(
            $backup->storage_path,
            'phr-native-v1-backup.zip',
            ['Content-Type' => 'application/zip'],
        );
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /** @return array<string, mixed> */
    private function payload(PhrNativeBackup $backup): array
    {
        return [
            'id' => $backup->id,
            'patient_id' => $backup->patient_id,
            'format' => 'phr-native-v1',
            'schema_version' => $backup->schema_version,
            'status' => $backup->status,
            'file_size_bytes' => $backup->file_size_bytes,
            'archive_sha256' => $backup->archive_sha256,
            'counts' => $backup->counts_json,
            'failure_category' => $backup->failure_category,
            'generated_at' => $backup->generated_at?->toIso8601String(),
            'expires_at' => $backup->expires_at?->toIso8601String(),
            'created_at' => $backup->created_at?->toIso8601String(),
            'download_url' => $backup->status === PhrNativeBackup::STATUS_READY
                ? URL::temporarySignedRoute('phr.native-backups.download', now()->addMinutes(15), ['backup' => $backup->id])
                : null,
        ];
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
