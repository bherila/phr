<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StorePatientRequest;
use App\Http\Requests\PHR\UpdatePatientRequest;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\Access\PhrPatientPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrPatientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()?->id;

        $patients = PhrPatient::query()
            ->accessibleBy($userId)
            ->with(['accessGrants.user'])
            ->orderBy('owner_user_id')
            ->orderBy('display_name')
            ->get()
            ->map(fn (PhrPatient $patient): array => $this->presenter->payload($patient, $userId))
            ->values();

        return response()->json(['patients' => $patients]);
    }

    public function store(StorePatientRequest $request): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $validated = $request->validated();

        $patient = DB::transaction(function () use ($userId, $validated): PhrPatient {
            $patient = PhrPatient::create([
                'owner_user_id' => $userId,
                ...$validated,
            ]);

            PhrPatientUserAccess::create([
                'patient_id' => $patient->id,
                'user_id' => $userId,
                'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
                'granted_by_user_id' => $userId,
                'granted_at' => now(),
            ]);

            return $patient;
        });

        $patient->load(['accessGrants.user']);

        return response()->json(['patient' => $this->presenter->payload($patient, $userId)], 201);
    }

    public function show(Request $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);

        return response()->json(['patient' => $this->presenter->payload($resolvedPatient, $userId)]);
    }

    public function update(UpdatePatientRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->writablePatient($patient, $userId);

        $resolvedPatient->update($request->validated());

        return response()->json(['patient' => $this->presenter->payload($resolvedPatient, $userId)]);
    }

    public function destroy(Request $request, int $patient): Response
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->ownedPatient($patient, $userId);

        $resolvedPatient->delete();

        return response()->noContent();
    }
}
