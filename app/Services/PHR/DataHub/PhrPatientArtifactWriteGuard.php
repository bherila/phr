<?php

namespace App\Services\PHR\DataHub;

use App\Models\PhrPatient;
use Illuminate\Support\Facades\DB;

/**
 * Serializes the durable-write boundary of every patient-owned blob with
 * aggregate deletion. Writers may prepare bytes before entering this guard,
 * but must not publish them to a PHR disk until the patient row is locked.
 */
final class PhrPatientArtifactWriteGuard
{
    /**
     * @template TResult
     *
     * @param  callable(PhrPatient): TResult  $writer
     * @return TResult
     */
    public function run(int $patientId, callable $writer): mixed
    {
        return DB::transaction(function () use ($patientId, $writer): mixed {
            $patient = PhrPatient::query()->whereKey($patientId)->lockForUpdate()->firstOrFail();

            return $writer($patient);
        }, 3);
    }
}
