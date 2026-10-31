<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Access\PhrPatientListService;
use App\Services\PHR\Preload\PhrPreloadBuilder;
use App\Services\PHR\Preload\PhrPreloadPlan;
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
        'review',
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
        private PhrPreloadBuilder $preload,
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

    public function config(Request $request): Response
    {
        return $this->shell('config', 'PHR Config', preload: $this->preload->build($request, PhrPreloadPlan::forSection('config')));
    }

    public function dataHub(Request $request): Response
    {
        return $this->shell('data-hub', 'PHR Data Hub', preload: $this->preload->build($request, PhrPreloadPlan::forSection('data-hub')));
    }

    public function patient(Request $request, int $patient): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $userId = (int) $user->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        // The raw path, so each segment is decoded once, as the browser reads it.
        preg_match('#^/phr/patient/\d+(/.*)?$#', $request->getPathInfo(), $match);
        $preload = $this->preload->build($request, PhrPreloadPlan::forPatient($resolvedPatient->id, $match[1] ?? ''));

        return $this->shell(null, 'PHR', $resolvedPatient->id, $this->accessService->canWrite($resolvedPatient, $userId), $preload);
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

    /**
     * Every shell response is private and uncached: a patient page carries health data in its
     * preload, and every page carries the person's patient list.
     */
    private function shell(?string $activeSection, string $title, ?int $patientId = null, bool $canManage = false, ?string $preload = null): Response
    {
        return response()->view('phr.shell', [...$this->shellData($activeSection, $title, $patientId, $canManage), 'phrPreload' => $preload])
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
