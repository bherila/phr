<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Fills\FillEobLinkDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Records what a person told an agent about a fill and its pharmacy claim. */
final class AgentFillEobLinkController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private FillEobLinkDecisionService $decisions,
    ) {}

    public function answer(Request $request, int $patient, int $fill): JsonResponse
    {
        $resolved = $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(FillEobLinkDecisionService::ACTIONS)],
            'eob_id' => ['required_if:action,'.FillEobLinkDecisionService::LINK, 'nullable', 'integer', 'min:1'],
        ]);
        $action = (string) $validated['action'];
        $outcome = $this->decisions->decide($resolved, $fill, $action, isset($validated['eob_id']) ? (int) $validated['eob_id'] : null);

        return response()->json([
            'resource_type' => 'medication_fill_eob_link_answer',
            'patient_id' => (int) $resolved->id,
            'outcome' => $outcome,
            'data' => ['fill_id' => $fill, 'action' => $action],
        ]);
    }
}
