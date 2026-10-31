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
    /**
     * The patient record pages the shell routes to at /phr/patient/{id}/{page}. Mirrors
     * PHR_PAGE_IDS in resources/js/phr/shell/phrRoutes.ts.
     */
    public const PATIENT_PAGES = [
        'summary',
        'health-log',
        'labs',
        'vitals',
        'office-visits',
        'medications',
        'conditions',
        'procedures',
        'immunizations',
        'allergies',
        'documents',
        'imaging',
        'access',
    ];

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
            'phrPatientList' => $this->patientList->bootstrapPayload((int) request()->user()?->id),
        ];
    }
}
