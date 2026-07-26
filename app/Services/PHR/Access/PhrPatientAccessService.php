<?php

namespace App\Services\PHR\Access;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class PhrPatientAccessService
{
    public function accessiblePatient(int $patientId, int $userId): PhrPatient
    {
        return PhrPatient::query()
            ->accessibleBy($userId)
            ->with(['accessGrants.user'])
            ->findOrFail($patientId);
    }

    public function writablePatient(int $patientId, int $userId): PhrPatient
    {
        $patient = $this->accessiblePatient($patientId, $userId);
        $this->ensureCanWrite($patient, $userId);

        return $patient;
    }

    public function ownedPatient(int $patientId, int $userId): PhrPatient
    {
        $patient = $this->accessiblePatient($patientId, $userId);
        $this->ensureOwner($patient, $userId);

        return $patient;
    }

    public function canWrite(PhrPatient $patient, int $userId): bool
    {
        if ((int) $patient->owner_user_id === $userId) {
            return true;
        }

        if (! $patient->relationLoaded('accessGrants')) {
            $patient->load('accessGrants');
        }

        return $patient->accessGrants->contains(
            fn (PhrPatientUserAccess $access): bool => (int) $access->user_id === $userId
                && in_array($access->access_level, [
                    PhrPatientUserAccess::LEVEL_OWNER,
                    PhrPatientUserAccess::LEVEL_MANAGER,
                ], true)
        );
    }

    public function ensureCanWrite(PhrPatient $patient, int $userId): void
    {
        if (! $this->canWrite($patient, $userId)) {
            throw new AuthorizationException('You do not have write access to this patient.');
        }
    }

    public function ensureOwner(PhrPatient $patient, int $userId): void
    {
        if ((int) $patient->owner_user_id !== $userId) {
            throw new AuthorizationException('Only the patient owner can perform this action.');
        }
    }

    /**
     * @return Builder<PhrPatient>
     */
    public function writablePatientsQuery(int $userId): Builder
    {
        return PhrPatient::query()
            ->where(function (Builder $query) use ($userId): void {
                $query
                    ->where('owner_user_id', $userId)
                    ->orWhereHas('accessGrants', function (Builder $accessQuery) use ($userId): void {
                        $accessQuery
                            ->where('user_id', $userId)
                            ->whereIn('access_level', [
                                PhrPatientUserAccess::LEVEL_OWNER,
                                PhrPatientUserAccess::LEVEL_MANAGER,
                            ]);
                    });
            });
    }
}
