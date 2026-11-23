<?php

namespace App\Services\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalUpsertResult;
use App\Models\PhrPatient;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * patients.update: the browser's patient edit (PATCH /patients/{patient}) for an
 * agent, applied to the patient as it now stands under its row lock and only when
 * the caller holds its current version. Real names are left to a person, as on
 * create, and deleting a patient stays a browser-only, owner-only workflow.
 */
final readonly class AgentPatientUpdateService
{
    public function __construct(private AgentClinicalRecordVersion $versions) {}

    /** @param array<string, mixed> $changes */
    public function update(PhrPatient $patient, string $expectedVersion, array $changes): ClinicalUpsertResult
    {
        return DB::transaction(function () use ($patient, $expectedVersion, $changes): ClinicalUpsertResult {
            $locked = PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $current = $this->versions->for($locked);
            if (! hash_equals($current, $expectedVersion)) {
                throw new ConflictHttpException('The patient changed; fetch it and retry with its current version.');
            }

            $locked->fill($changes);
            if (! $locked->isDirty()) {
                // An exact no-op answers with the patient as stored, not as filled.
                return new ClinicalUpsertResult($locked->refresh(), ClinicalUpsertResult::UNCHANGED, $current);
            }
            $locked->save();
            $locked->refresh();

            return new ClinicalUpsertResult($locked, ClinicalUpsertResult::UPDATED, $this->versions->for($locked));
        }, 3);
    }
}
