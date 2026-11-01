<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Services\PHR\Ndc\NdcLabelService;
use App\Services\PHR\Ndc\NdcLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** NDC autofill for the manual entry form. The browser never calls openFDA itself. */
class NdcController extends Controller
{
    public function __construct(private NdcLookupService $ndc, private NdcLabelService $labels) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['ndc' => ['required', 'string', 'min:1', 'max:64']]);

        return response()->json($this->ndc->lookup($validated['ndc'])->toArray());
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'strength' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dosage_form' => ['sometimes', 'nullable', 'string', 'max:100'],
            'route' => ['sometimes', 'nullable', 'string', 'max:100'],
            'labeler' => ['sometimes', 'nullable', 'string', 'max:255'],
            'package' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $validated['package_size'] = $validated['package'] ?? null;
        unset($validated['package']);

        return response()->json(['candidates' => $this->ndc->search($validated)['candidates']]);
    }

    /** The FDA drug label sections for a package NDC or SPL set id (the fill's drug panel). */
    public function label(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ndc_10' => ['required_without:spl_set_id', 'nullable', 'string', 'max:64'],
            'spl_set_id' => ['required_without:ndc_10', 'nullable', 'string', 'max:64'],
        ]);

        return response()->json($this->labels->label($validated['ndc_10'] ?? null, $validated['spl_set_id'] ?? null));
    }
}
