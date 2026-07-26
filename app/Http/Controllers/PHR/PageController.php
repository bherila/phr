<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    public function index(): RedirectResponse
    {
        return redirect('/phr/patients');
    }

    public function patients(): View
    {
        return $this->shell('patients', 'PHR Patients');
    }

    public function managePatients(): View
    {
        return $this->shell('manage-patients', 'Manage Patients');
    }

    public function imports(): View
    {
        return $this->shell('imports', 'PHR Imports');
    }

    public function config(): View
    {
        return $this->shell('config', 'PHR Config');
    }

    public function patient(Request $request, int $patient): View
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $userId = (int) $user->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        return $this->shell(null, 'PHR', $resolvedPatient->id, $this->accessService->canWrite($resolvedPatient, $userId));
    }

    public function explore3d(Request $request, int $patient, int $series): View
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $resolvedPatient = $this->accessService->accessiblePatient($patient, (int) $user->id);

        return view('phr.explore3d', [
            'patientId' => $resolvedPatient->id,
            'seriesId' => $series,
            'title' => 'Explore in 3D',
        ]);
    }

    private function shell(?string $activeSection, string $title, ?int $patientId = null, bool $canManage = false): View
    {
        return view('phr.shell', [
            'activeSection' => $activeSection,
            'patientId' => $patientId,
            'canManage' => $canManage,
            'title' => $title,
            'backUrl' => $this->backUrl(),
        ]);
    }

    /**
     * The navbar's "back" affordance exits PHR entirely rather than navigating within it, so it
     * points at the parent site (also the OAuth identity provider PHR authenticates against)
     * instead of a hardcoded literal.
     */
    private function backUrl(): string
    {
        return rtrim((string) config('services.identity_provider.base_url', 'https://bherila.net'), '/');
    }
}
