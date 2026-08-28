<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Import\PhrEobReconciliationService;
use App\Services\PHR\Import\PhrReconciliationPreviewChangedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Privileged, count-only previews over the existing source reconcilers. */
final class AgentReconciliationController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $access,
        private PhrEobReconciliationService $reconciliations,
    ) {}

    public function preview(Request $request, int $patient, string $reconciliation): JsonResponse
    {
        $this->validateReconciliation($reconciliation);
        $resolved = $this->access->accessiblePatientWithCurrentGrant($patient, (int) $request->user('api')?->id);

        return response()->json([
            'resource_type' => 'reconciliation_preview',
            'patient_id' => (int) $resolved->id,
            'data' => $this->reconciliations->preview($resolved, $reconciliation),
        ]);
    }

    public function apply(Request $request, int $patient, string $reconciliation): JsonResponse
    {
        $this->validateReconciliation($reconciliation);
        $validated = $request->validate(['preview_digest' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/']]);
        $resolved = $this->access->writablePatient($patient, (int) $request->user('api')?->id);

        try {
            $preview = $this->reconciliations->apply($resolved, $reconciliation, (string) $validated['preview_digest']);
        } catch (PhrReconciliationPreviewChangedException) {
            throw new HttpException(409, 'Reconciliation preview has changed; request a new preview before applying.');
        }

        return response()->json([
            'resource_type' => 'reconciliation_apply',
            'patient_id' => (int) $resolved->id,
            'outcome' => 'applied',
            'data' => $preview,
        ]);
    }

    private function validateReconciliation(string $reconciliation): void
    {
        validator(['reconciliation' => $reconciliation], [
            'reconciliation' => ['required', 'string', Rule::in(PhrEobReconciliationService::TYPES)],
        ])->validate();
    }
}
