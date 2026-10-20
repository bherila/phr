<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Models\PhrEob;
use App\Models\PhrNativeRecordIdentity;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Identity\PhrPublicIds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EobPageController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function resolve(Request $request, string $public_id): RedirectResponse
    {
        $identity = PhrNativeRecordIdentity::query()
            ->where('record_table', PhrPublicIds::EOBS)
            ->where('native_id', $public_id)
            ->firstOrFail();
        $patient = $this->accessService->accessiblePatient($identity->patient_id, (int) $request->user()?->id);
        $eob = PhrEob::query()->where('patient_id', $patient->id)->findOrFail($identity->record_id);

        return redirect()->route('phr.eobs.show', ['patient' => $patient->id, 'eob' => $eob->id])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function show(Request $request, int $patient, int $eob): Response
    {
        $resolvedPatient = $this->accessService->accessiblePatient($patient, (int) $request->user()?->id);
        $resolvedEob = PhrEob::query()->where('patient_id', $resolvedPatient->id)->findOrFail($eob);
        $sourceDocument = $resolvedEob->sourceDocument()->where('patient_id', $resolvedPatient->id)->first();

        return response()->view('phr.eob', [
            'patient' => $resolvedPatient,
            'eob' => $resolvedEob,
            'sourceDocument' => $sourceDocument,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
