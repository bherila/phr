<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StorePatientAccessRequest;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientAccessController extends Controller
{
    public function __construct(
        private PhrPatientAccessService $accessService,
        private PhrPatientArtifactWriteGuard $writeGuard,
    ) {}

    /**
     * Grant a user access to a patient the caller owns.
     *
     * The response is deliberately uniform — the same 201 and the same body —
     * whether the address belongs to an account, belongs to the owner, or
     * belongs to nobody. Echoing the grant (or its absence) back would make
     * this an account-enumeration oracle for any authenticated user with a
     * throwaway patient. The client reloads the patient to render the grant
     * list, which is the owner's own data.
     */
    public function store(StorePatientAccessRequest $request, int $patient): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $this->accessService->ownedPatient($patient, $userId);

        $validated = $request->validated();
        $this->writeGuard->run($patient, function (PhrPatient $locked) use ($userId, $validated): void {
            $this->accessService->ensureOwner($locked, $userId);
            $targetUser = User::query()->where('email', $validated['email'])->first();

            // Missing accounts and the owner's own address remain uniform no-ops.
            if ($targetUser !== null && (int) $targetUser->id !== $userId) {
                PhrPatientUserAccess::updateOrCreate(
                    ['patient_id' => $locked->id, 'user_id' => $targetUser->id],
                    [
                        'access_level' => $validated['access_level'],
                        'granted_by_user_id' => $userId,
                        'granted_at' => now(),
                    ],
                );
            }
        });

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request, int $patient, int $access): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $this->accessService->ownedPatient($patient, $userId);

        $this->writeGuard->run($patient, function (PhrPatient $locked) use ($userId, $access): void {
            $this->accessService->ensureOwner($locked, $userId);
            PhrPatientUserAccess::query()
                ->where('patient_id', $locked->id)
                ->where('access_level', '!=', PhrPatientUserAccess::LEVEL_OWNER)
                ->findOrFail($access)
                ->delete();
        });

        return response()->json(['ok' => true]);
    }
}
