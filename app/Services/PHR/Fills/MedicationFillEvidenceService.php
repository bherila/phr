<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrEobLine;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrPatient;
use Illuminate\Database\Eloquent\SoftDeletingScope;
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

    /**
     * Remembers that a connection's own identity for a fill resolved to a fill the patient
     * already had, so its retry lands there again and cannot recreate the fill if a person
     * deleted it.
     */
    public function alias(PhrPatient $patient, PhrMedicationFill $fill, string $importSource, string $externalId): void
    {
        PhrMedicationFillEvidence::query()->firstOrCreate([
            'fill_id' => $fill->id,
            'evidence_key' => substr(hash('sha256', 'alias|'.$importSource.'|'.$externalId), 0, 40),
        ], [
            'patient_id' => $patient->id,
            'source_kind' => 'alias',
            'import_source' => $importSource,
            'external_id' => $externalId,
        ]);
    }

    /** Flags a fill for the reviewer: it may duplicate another fill of the patient. */
    public function flagPossibleDuplicate(PhrPatient $patient, PhrMedicationFill $fill, PhrMedicationFill $candidate): void
    {
        PhrMedicationFillEvidence::query()->firstOrCreate([
            'fill_id' => $fill->id,
            'evidence_key' => substr(hash('sha256', 'possible_duplicate|'.$candidate->id), 0, 40),
        ], [
            'patient_id' => $patient->id,
            'source_kind' => 'possible_duplicate',
            'related_fill_id' => $candidate->id,
        ]);
    }

    /**
     * The fill a connection's own flagged fill was merged into, when a person (or the agent)
     * decided it was the same fill: the flagged row is retracted but its identity points here.
     *
     * @param  array{patient_id: int, import_source: string, external_id: string}  $identity
     */
    public function mergedSurvivor(array $identity): ?PhrMedicationFill
    {
        $withdrawn = PhrMedicationFill::withoutGlobalScope(SoftDeletingScope::class)->where($identity)
            ->whereNull('deleted_at')->whereNotNull('retracted_at')->value('id');
        if ($withdrawn === null) {
            return null;
        }
        $survivorId = PhrMedicationFillEvidence::query()->where('fill_id', $withdrawn)
            ->where('source_kind', 'duplicate_merged')->value('related_fill_id');

        return $survivorId === null ? null : PhrMedicationFill::query()->whereNull('retracted_at')->whereKey($survivorId)->first();
    }

    /** The fill (live or withdrawn) a connection's identity was merged into, if any. */
    public function findAlias(PhrPatient $patient, string $importSource, string $externalId): ?PhrMedicationFill
    {
        $fillId = PhrMedicationFillEvidence::query()
            ->where('patient_id', $patient->id)
            ->where('source_kind', 'alias')
            ->where('import_source', $importSource)
            ->where('external_id', $externalId)
            ->value('fill_id');

        return $fillId === null ? null : PhrMedicationFill::withoutGlobalScope(SoftDeletingScope::class)->whereKey($fillId)->first();
    }
}
