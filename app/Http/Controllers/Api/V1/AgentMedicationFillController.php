<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentApi\UpsertMedicationFillRequest;
use App\Http\Resources\PHR\MedicationFillResource;
use App\Http\Resources\PHR\MedicationResource;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Services\AgentApi\MedicationFillUpsertService;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Ndc\NdcLookupService;
use App\Services\PHR\Ndc\NdcResolution;
use App\Support\AgentApi\AgentApiClientIdentity;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentMutationResponse;
use Illuminate\Http\JsonResponse;

final class AgentMedicationFillController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private MedicationFillUpsertService $upserts,
        private NdcLookupService $ndc,
    ) {}

    public function upsert(UpsertMedicationFillRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user('api')?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);
        $result = $this->upserts->upsert(
            $resolvedPatient,
            AgentApiClientIdentity::fromRequest($request),
            $request->upsertData(),
        );
        $readable = (bool) $request->user('api')?->tokenCan(AgentApiScopes::CLINICAL_READ);
        $medication = $result->medication;

        return response()->json(
            AgentMutationResponse::payload(
                $request,
                'medication-fills',
                $resolvedPatient->id,
                $result->outcome,
                AgentApiScopes::CLINICAL_READ,
                $result->record,
                fn (): array => (new MedicationFillResource($result->record))->resolve($request),
                $result->version,
                [
                    'medication' => $medication instanceof PhrMedication
                        ? ($readable
                            ? (new MedicationResource($medication))->resolve($request)
                            : ['id' => $medication->id, 'review_status' => $medication->review_status])
                        : null,
                    'medication_created' => $result->medicationCreated,
                    // Whether the fill is one the patient already had (matched), or may be (flagged).
                    'dedupe' => $result->dedupe,
                    // Stored NDC and drug detail is clinical content: a write-only caller gets none of it.
                    'ndc' => $readable ? $this->ndcBlock($result->record, $result->resolution) : null,
                ],
            ),
            $result->outcome === ClinicalUpsertResult::CREATED ? 201 : 200,
        );
    }

    /**
     * How the NDC resolved. A retry has no fresh resolution, so the stored one is
     * described; an ambiguous fill lists its candidates again (from cache) so the
     * agent can put them to the person.
     *
     * @return array<string, mixed>
     */
    private function ndcBlock(PhrMedicationFill $fill, ?NdcResolution $resolution): array
    {
        if ($resolution === null && $fill->ndc_status === PhrMedicationFill::NDC_AMBIGUOUS && $fill->ndc_as_captured !== null) {
            $resolution = $this->ndc->lookup($fill->ndc_as_captured);
        }
        if ($resolution !== null) {
            return $resolution->toArray();
        }

        $resolved = $fill->ndc_status === PhrMedicationFill::NDC_RESOLVED;
        $selected = ! $resolved ? null : [
            'ndc_10' => $fill->ndc_10,
            'ndc_11' => $fill->ndc_11,
            'product_ndc' => $fill->fda_snapshot['product_ndc'] ?? null,
            'drug' => (new MedicationFillResource($fill))->resolve()['drug'],
            'match_reasons' => ['package NDC exact'],
            'fetched_at' => $fill->fda_fetched_at?->toIso8601String(),
        ];

        return [
            'status' => $resolved ? 'resolved' : 'unresolved',
            'input' => $fill->ndc_as_captured ?? '',
            'selected' => $selected,
            'candidates' => $selected === null ? [] : [$selected],
            'reason' => null,
            'next_step' => null,
        ];
    }
}
