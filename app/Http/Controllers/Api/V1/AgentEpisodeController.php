<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Encounters\PhrEpisodeLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Group an encounter under an episode of care, or take it out (#415). The episode itself
 * is a clinical resource (episodes.upsert, clinical.get), whose reads list the grouped
 * visits as encounter_ids.
 */
final class AgentEpisodeController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrEpisodeLinks $links,
    ) {}

    public function link(Request $request, int $patient, int $episode): JsonResponse
    {
        $validated = $request->validate([
            'office_visit_id' => ['required', 'integer', 'min:1'],
        ]);
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $visit = (int) $validated['office_visit_id'];

        return $this->response((int) $resolved->id, $this->links->link((int) $resolved->id, $episode, $visit), $episode, $visit);
    }

    public function unlink(Request $request, int $patient, int $episode, int $visit): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);

        return $this->response((int) $resolved->id, $this->links->unlink((int) $resolved->id, $episode, $visit), $episode, $visit);
    }

    private function response(int $patientId, string $outcome, int $episodeId, int $visitId): JsonResponse
    {
        return response()->json([
            'resource_type' => 'episode_encounter_link',
            'patient_id' => $patientId,
            'outcome' => $outcome,
            'data' => ['episode_id' => $episodeId, 'office_visit_id' => $visitId],
        ]);
    }
}
