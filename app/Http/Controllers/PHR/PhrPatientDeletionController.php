<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Jobs\PHR\CleanupDeletedPhrPatientArtifactsJob;
use App\Models\PhrPatientDeletion;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientDeletionPresenter;
use App\Services\PHR\DataHub\PhrPatientDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PhrPatientDeletionController extends Controller
{
    public function __construct(
        private readonly PhrPatientAccessService $accessService,
        private readonly PhrPatientDeletionService $deletionService,
        private readonly PhrPatientDeletionPresenter $presenter,
    ) {}

    public function preview(Request $request, int $patient): JsonResponse
    {
        $resolved = $this->accessService->ownedPatient($patient, (int) $request->user()?->id);

        return $this->privateJson(['deletion_preview' => $this->deletionService->preview($resolved)->publicPayload()]);
    }

    public function show(Request $request, PhrPatientDeletion $deletion): JsonResponse
    {
        abort_unless((int) $deletion->actor_user_id === (int) $request->user()?->id, 404);

        return $this->privateJson(['deletion' => $this->presenter->payload($deletion)]);
    }

    public function retry(Request $request, PhrPatientDeletion $deletion): JsonResponse
    {
        abort_unless((int) $deletion->actor_user_id === (int) $request->user()?->id, 404);
        if ($deletion->status !== PhrPatientDeletion::STATUS_COMPLETED) {
            try {
                CleanupDeletedPhrPatientArtifactsJob::dispatch($deletion->id);
            } catch (Throwable) {
                // A synchronous driver may execute (and fail) during dispatch. The
                // durable work rows remain authoritative and can be retried again.
            }
        }

        return $this->privateJson(['deletion' => $this->presenter->payload($deletion->refresh())], 202);
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
