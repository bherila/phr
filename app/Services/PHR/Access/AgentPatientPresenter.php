<?php

namespace App\Services\PHR\Access;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;

final class AgentPatientPresenter
{
    public function __construct(private PhrPatientAccessService $accessService) {}

    /** @return array<string, mixed> */
    public function payload(PhrPatient $patient, int $userId, bool $includeNotes = false): array
    {
        $isOwner = (int) $patient->owner_user_id === $userId;
        $grant = $isOwner
            ? null
            : $patient->accessGrants->first(
                fn (PhrPatientUserAccess $access): bool => (int) $access->user_id === $userId,
            );

        $payload = [
            'id' => $patient->id,
            'display_name' => $patient->display_name,
            'relationship' => $patient->relationship,
            'birth_date' => $patient->birth_date?->toDateString(),
            'sex_at_birth' => $patient->sex_at_birth,
            'archived_at' => $patient->archived_at?->toDateTimeString(),
            'created_at' => $patient->created_at?->toDateTimeString(),
            'updated_at' => $patient->updated_at?->toDateTimeString(),
            'access' => [
                'level' => $isOwner ? PhrPatientUserAccess::LEVEL_OWNER : $grant?->access_level,
                'is_owner' => $isOwner,
                'can_write' => $this->accessService->canWrite($patient, $userId),
                'granted_at' => $grant?->granted_at?->toDateTimeString(),
            ],
        ];

        if ($includeNotes) {
            $payload['notes'] = $patient->notes;
        }

        return $payload;
    }
}
