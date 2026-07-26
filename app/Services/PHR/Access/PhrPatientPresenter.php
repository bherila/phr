<?php

namespace App\Services\PHR\Access;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use Illuminate\Database\Eloquent\Collection;

class PhrPatientPresenter
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(PhrPatient $patient, int $userId): array
    {
        $canShare = (int) $patient->owner_user_id === $userId;
        $accessLevel = $canShare
            ? PhrPatientUserAccess::LEVEL_OWNER
            : $this->accessLevelForUser($patient->accessGrants, $userId);

        return [
            'id' => $patient->id,
            'owner_user_id' => $patient->owner_user_id,
            'display_name' => $patient->display_name,
            'relationship' => $patient->relationship,
            'birth_date' => $patient->birth_date?->toDateString(),
            'sex_at_birth' => $patient->sex_at_birth,
            'notes' => $patient->notes,
            'archived_at' => $patient->archived_at?->toDateTimeString(),
            'created_at' => $patient->created_at?->toDateTimeString(),
            'updated_at' => $patient->updated_at?->toDateTimeString(),
            'access_level' => $accessLevel,
            'can_manage' => $this->accessService->canWrite($patient, $userId),
            'can_share' => $canShare,
            'access_grants' => $canShare
                ? $patient->accessGrants
                    ->map(fn (PhrPatientUserAccess $access): array => [
                        'id' => $access->id,
                        'user_id' => $access->user_id,
                        'user_name' => $access->user?->name,
                        'user_email' => $access->user?->email,
                        'access_level' => $access->access_level,
                        'granted_at' => $access->granted_at?->toDateTimeString(),
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @param  Collection<int, PhrPatientUserAccess>  $accessGrants
     */
    private function accessLevelForUser(Collection $accessGrants, int $userId): ?string
    {
        $access = $accessGrants->first(fn (PhrPatientUserAccess $grant): bool => (int) $grant->user_id === $userId);

        return $access?->access_level;
    }
}
