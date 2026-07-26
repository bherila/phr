<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\StorePatientAccessRequest;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientAccessController extends Controller
{
    public function __construct(private PhrPatientAccessService $accessService) {}

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
        $resolvedPatient = $this->accessService->ownedPatient($patient, $userId);

        $validated = $request->validated();
        $targetUser = User::query()->where('email', $validated['email'])->first();

        // A missing account and the owner's own address are both no-ops. The
        // owner already has access, so re-granting would be meaningless.
        if ($targetUser !== null && (int) $targetUser->id !== $userId) {
            PhrPatientUserAccess::updateOrCreate(
                [
                    'patient_id' => $resolvedPatient->id,
                    'user_id' => $targetUser->id,
                ],
                [
                    'access_level' => $validated['access_level'],
                    'granted_by_user_id' => $userId,
                    'granted_at' => now(),
                ],
            );
        }

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request, int $patient, int $access): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->ownedPatient($patient, $userId);

        PhrPatientUserAccess::query()
            ->where('patient_id', $resolvedPatient->id)
            ->where('access_level', '!=', PhrPatientUserAccess::LEVEL_OWNER)
            ->findOrFail($access)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
