<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PHR\Ndc\NdcLabelService;
use App\Services\PHR\Ndc\NdcLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NDC lookup and drug search for agents. They read the public openFDA directory
 * and touch no patient data, but stay behind clinical:read like the records
 * they exist to describe. The agent never calls openFDA itself.
 */
final class AgentNdcController extends Controller
{
    public function __construct(private NdcLookupService $ndc, private NdcLabelService $labels) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['ndc' => ['required', 'string', 'min:1', 'max:64']]);

        return response()->json($this->ndc->lookup($validated['ndc'])->toArray());
    }

    public function label(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ndc_10' => ['required_without:spl_set_id', 'nullable', 'string', 'max:64'],
            'spl_set_id' => ['required_without:ndc_10', 'nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->labels->label($validated['ndc_10'] ?? null, $validated['spl_set_id'] ?? null));
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'strength' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dosage_form' => ['sometimes', 'nullable', 'string', 'max:100'],
            'route' => ['sometimes', 'nullable', 'string', 'max:100'],
            'labeler' => ['sometimes', 'nullable', 'string', 'max:255'],
            'package_size' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        return response()->json([
            ...$this->ndc->search($validated),
            'next_step' => 'Never pick a candidate yourself. Show these to the person, then pass the chosen ndc_10 as ndc_candidate when writing the fill.',
        ]);
    }
}
