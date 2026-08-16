<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Models\PhrNativeRestoreAttempt;
use App\Services\PHR\NativeBackup\NativeRestoreException;
use App\Services\PHR\NativeBackup\PhrNativeRestorePresenter;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PhrNativeRestoreController extends Controller
{
    public function __construct(
        private readonly PhrNativeRestoreService $service,
        private readonly PhrNativeRestorePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $attempts = PhrNativeRestoreAttempt::query()
            ->where('actor_user_id', (int) $request->user()?->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (PhrNativeRestoreAttempt $attempt): array => $this->presenter->payload($attempt))
            ->values();

        return $this->privateJson(['restores' => $attempts]);
    }

    public function startUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_file_size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('phr.native_backup_max_uncompressed_bytes')],
            'restore_access_grants' => ['sometimes', 'boolean'],
        ]);
        try {
            $attempt = $this->service->startUpload(
                (int) $request->user()?->id,
                (int) $validated['source_file_size_bytes'],
                (bool) ($validated['restore_access_grants'] ?? false),
            );
        } catch (NativeRestoreException $exception) {
            return $this->privateJson(['error' => $exception->failureCategory], 422);
        }

        return $this->privateJson(['restore' => $this->presenter->payload($attempt)], 201);
    }

    public function appendChunk(Request $request, PhrNativeRestoreAttempt $restore): JsonResponse
    {
        abort_unless((int) $restore->actor_user_id === (int) $request->user()?->id, 404);
        $validated = $request->validate([
            'chunk' => ['required', 'file'],
            'offset' => ['required', 'integer', 'min:0'],
        ]);
        try {
            $updated = $this->service->appendChunk(
                $restore,
                (int) $request->user()?->id,
                $validated['chunk'],
                (int) $validated['offset'],
            );
        } catch (NativeRestoreException $exception) {
            return $this->privateJson(['error' => $exception->failureCategory], 409);
        }

        return $this->privateJson(['restore' => $this->presenter->payload($updated)]);
    }

    public function preview(Request $request, PhrNativeRestoreAttempt $restore): JsonResponse
    {
        abort_unless((int) $restore->actor_user_id === (int) $request->user()?->id, 404);
        try {
            $queued = $this->service->queuePreview($restore, (int) $request->user()?->id);
        } catch (NativeRestoreException $exception) {
            return $this->privateJson(['error' => $exception->failureCategory], 409);
        }

        return $this->privateJson(['restore' => $this->presenter->payload($queued)], 202);
    }

    public function show(Request $request, PhrNativeRestoreAttempt $restore): JsonResponse
    {
        abort_unless((int) $restore->actor_user_id === (int) $request->user()?->id, 404);

        return $this->privateJson(['restore' => $this->presenter->payload($restore)]);
    }

    public function apply(Request $request, PhrNativeRestoreAttempt $restore): JsonResponse
    {
        abort_unless((int) $restore->actor_user_id === (int) $request->user()?->id, 404);
        $validated = $request->validate([
            'confirmation' => ['required', 'string', Rule::in(['RESTORE'])],
            'plan_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'restore_access_grants' => ['required', 'boolean'],
        ]);
        try {
            $queued = $this->service->queue(
                $restore,
                (int) $request->user()?->id,
                (string) $validated['plan_digest'],
                (bool) $validated['restore_access_grants'],
            );
        } catch (NativeRestoreException $exception) {
            return $this->privateJson(['error' => $exception->failureCategory], 409);
        }

        return $this->privateJson(['restore' => $this->presenter->payload($queued)], 202);
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
