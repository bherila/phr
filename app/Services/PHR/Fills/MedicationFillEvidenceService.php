<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrEobLine;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrPatient;
use Illuminate\Validation\ValidationException;

/**
 * Records what else vouches for a fill: another document that shows the same
 * dispensing, or the EOB line that adjudicated it. Recording is idempotent, so
 * reading the same receipt or EOB again adds nothing.
 */
final class MedicationFillEvidenceService
{
    /**
     * The EOB line must be this patient's; a line of another patient's claim is never linked.
     *
     * @throws ValidationException
     */
    public function assertEobLine(PhrPatient $patient, ?int $eobLineId): void
    {
        if ($eobLineId === null) {
            return;
        }
        if (! PhrEobLine::query()->where('patient_id', $patient->id)->whereKey($eobLineId)->exists()) {
            throw ValidationException::withMessages(['data.eob_line_id' => 'This EOB line does not belong to the patient.']);
        }
    }

    /** @return bool whether a new piece of evidence was recorded */
    public function record(PhrPatient $patient, PhrMedicationFill $fill, ?int $documentId, ?int $eobLineId, string $sourceKind, ?string $locator): bool
    {
        if ($documentId === null && $eobLineId === null) {
            return false;
        }
        // The document the fill was first read from is already its provenance.
        if ($eobLineId === null && $documentId === $fill->source_document_id) {
            return false;
        }

        $evidence = PhrMedicationFillEvidence::query()->firstOrCreate([
            'fill_id' => $fill->id,
            'evidence_key' => PhrMedicationFillEvidence::keyFor($documentId, $eobLineId, $sourceKind, $locator),
        ], [
            'patient_id' => $patient->id,
            'document_id' => $documentId,
            'eob_line_id' => $eobLineId,
            'source_kind' => $sourceKind,
            'source_locator' => $locator,
        ]);

        return $evidence->wasRecentlyCreated;
    }
}
