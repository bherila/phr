<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Access\PhrPatientListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrPatientListService $patientList,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect('/phr/patients');
    }

    public function patients(): Response
    {
        return $this->shell('patients', 'PHR Patients');
    }

    public function managePatients(): Response
    {
        return $this->shell('manage-patients', 'Manage Patients');
    }

    public function imports(): Response
    {
        return $this->shell('imports', 'PHR Imports');
    }

    public function config(): Response
    {
        return $this->shell('config', 'PHR Config');
    }

    public function dataHub(): Response
    {
        return $this->shell('data-hub', 'PHR Data Hub');
    }

    public function patient(Request $request, int $patient): Response
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

    private function shell(?string $activeSection, string $title, ?int $patientId = null, bool $canManage = false): Response
    {
        return response()->view('phr.shell', $this->shellData($activeSection, $title, $patientId, $canManage))
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /** @return array<string, mixed> */
    private function shellData(?string $activeSection, string $title, ?int $patientId = null, bool $canManage = false): array
    {
        return [
            'activeSection' => $activeSection,
            'patientId' => $patientId,
            'canManage' => $canManage,
            'title' => $title,
            'backUrl' => $this->backUrl(),
            'phrPatientList' => $this->patientList->bootstrapPayload((int) request()->user()?->id),
        ];
    }

    /**
     * The navbar's "back" affordance exits PHR entirely rather than navigating within it, so it
     * points at the parent site.
     *
     * This is deliberately not the OAuth provider base URL. The two coincided only while
     * identity was served from the parent site; the identity provider now lives on its own
     * subdomain and has nothing to navigate back to.
     */
    private function backUrl(): string
    {
        return (string) config('phr.parent_site_url');
    }
}
