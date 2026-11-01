<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Keeps every fill attached to a medication-list entry: the patient's matching
 * medication when there is one, otherwise a new entry that waits for review.
 *
 * A match is, in order: the same RxNorm code; the same product (NDC labeler and
 * product segments); the same substances, strength and dosage form. A fill
 * whose drug did not resolve matches a medication of the same name.
 */
class MedicationFillMedicationLinker
{
    /** The import source of medications this linker creates; the external ID names the fill. */
    public const string IMPORT_SOURCE = 'phr-fill';

    /**
     * @return array{medication: PhrMedication, created: bool}
     */
    public function attach(PhrPatient $patient, PhrMedicationFill $fill, ?int $explicitMedicationId = null, ?int $previousMedicationId = null): array
    {
        // A caller that already moved the column (a plain edit) names the medication it left.
        $previousId = $previousMedicationId ?? $fill->medication_id;
        $previous = $previousId === null ? null : PhrMedication::query()->find($previousId);

        $match = $explicitMedicationId !== null
            ? $this->livePatientMedications($patient)->find($explicitMedicationId)
            : $this->findMatch($patient, $fill);
        $created = false;
        if (! $match instanceof PhrMedication) {
            $match = $this->createFor($patient, $fill);
            $created = true;
        }

        if ($fill->medication_id !== $match->id) {
            $fill->forceFill(['medication_id' => $match->id])->save();
        }
        if ($previous !== null && $previous->id !== $match->id) {
            $this->discardIfOrphan($previous, $fill);
        }

        return ['medication' => $match, 'created' => $created];
    }

    /**
     * A fill left a medication (was deleted, retracted or moved): an entry this
     * linker created, that nobody reviewed and no live fill uses, goes with it.
     */
    public function release(PhrMedicationFill $fill, ?int $medicationId = null): void
    {
        $id = $medicationId ?? $fill->medication_id;
        $medication = $id === null ? null : PhrMedication::query()->find($id);
        if ($medication !== null) {
            $this->discardIfOrphan($medication, $fill);
        }
    }

    /**
     * Keeps the fill's current medication when it still fits the fill's drug;
     * otherwise runs the link-or-create match.
     *
     * @return array{medication: PhrMedication, created: bool}
     */
    public function reconcile(PhrPatient $patient, PhrMedicationFill $fill): array
    {
        $current = $fill->medication_id === null ? null : $this->livePatientMedications($patient)->find($fill->medication_id);
        if ($current instanceof PhrMedication && $this->fits($current, $fill)) {
            return ['medication' => $current, 'created' => false];
        }

        return $this->attach($patient, $fill);
    }

    private function fits(PhrMedication $medication, PhrMedicationFill $fill): bool
    {
        if ($fill->rxcui !== null && $fill->rxcui !== '' && $medication->rxnorm_code === $fill->rxcui) {
            return true;
        }
        $signature = $this->signature($fill);
        if ($signature === null) {
            return mb_strtolower($medication->name) === mb_strtolower(trim((string) $fill->drug_as_printed));
        }
        [$name, $dose, $unit] = $this->nameAndDose($fill);
        if (mb_strtolower($medication->name) === mb_strtolower($name)
            && mb_strtolower(trim($medication->dose.' '.$medication->dose_unit)) === mb_strtolower(trim($dose.' '.$unit))) {
            return true;
        }
        $product = $fill->ndc_11 === null ? null : substr($fill->ndc_11, 0, 9);
        foreach (PhrMedicationFill::query()->where('medication_id', $medication->id)->where('id', '!=', $fill->id)->whereNull('retracted_at')->get() as $other) {
            if (($product !== null && $other->ndc_11 !== null && substr($other->ndc_11, 0, 9) === $product) || $this->signature($other) === $signature) {
                return true;
            }
        }

        return false;
    }

    /** @return Builder<PhrMedication> */
    private function livePatientMedications(PhrPatient $patient): Builder
    {
        return PhrMedication::query()
            ->where('patient_id', $patient->id)
            ->whereNull('retracted_at')
            ->where('review_status', '!=', PhrReviewStatus::REJECTED);
    }

    private function findMatch(PhrPatient $patient, PhrMedicationFill $fill): ?PhrMedication
    {
        $medications = $this->livePatientMedications($patient);

        if ($fill->rxcui !== null && $fill->rxcui !== '') {
            $byCode = (clone $medications)->where('rxnorm_code', $fill->rxcui)->orderBy('id')->first();
            if ($byCode instanceof PhrMedication) {
                return $byCode;
            }
        }

        $others = PhrMedicationFill::query()
            ->where('patient_id', $patient->id)
            ->where('id', '!=', $fill->id)
            ->whereNull('retracted_at')
            ->whereNotNull('medication_id')
            ->orderBy('id')
            ->get();

        $product = $fill->ndc_11 === null ? null : substr($fill->ndc_11, 0, 9);
        if ($product !== null) {
            foreach ($others as $other) {
                if ($other->ndc_11 !== null && substr($other->ndc_11, 0, 9) === $product
                    && ($medication = (clone $medications)->find($other->medication_id)) instanceof PhrMedication) {
                    return $medication;
                }
            }
        }

        $signature = $this->signature($fill);
        if ($signature !== null) {
            foreach ($others as $other) {
                if ($this->signature($other) === $signature
                    && ($medication = (clone $medications)->find($other->medication_id)) instanceof PhrMedication) {
                    return $medication;
                }
            }
            // A medication entered by hand has no fills to compare, so it matches on the
            // name and dose a new entry would have been given.
            [$name, $dose, $unit] = $this->nameAndDose($fill);
            foreach ((clone $medications)->orderBy('id')->get() as $medication) {
                if (mb_strtolower($medication->name) === mb_strtolower($name)
                    && mb_strtolower(trim($medication->dose.' '.$medication->dose_unit)) === mb_strtolower(trim($dose.' '.$unit))) {
                    return $medication;
                }
            }

            return null;
        }

        $printed = mb_strtolower(trim((string) $fill->drug_as_printed));
        if ($printed === '') {
            return null;
        }

        return (clone $medications)->whereRaw('LOWER(name) = ?', [$printed])->orderBy('id')->first();
    }

    /**
     * Each ingredient with its own strength, plus the dosage form, lowercased and
     * ordered, or null when the drug is unknown. Pairs stay together: a combination of
     * A 5 mg + B 10 mg is not A 10 mg + B 5 mg.
     */
    private function signature(PhrMedicationFill $fill): ?string
    {
        $substances = array_values(array_map(mb_strtolower(...), array_filter((array) $fill->substance_names, is_string(...))));
        if ($substances === []) {
            return null;
        }
        $strengths = array_values(array_map(mb_strtolower(...), array_filter((array) $fill->strengths, is_string(...))));
        // openFDA lists each ingredient's strength beside its name, in the same order. If the
        // counts disagree they cannot be paired, so the strengths are kept as a separate list.
        $ingredients = count($strengths) === count($substances)
            ? array_map(static fn (string $name, string $strength): string => $name.'|'.$strength, $substances, $strengths)
            : [...$substances, '|'.implode(',', $strengths)];
        sort($ingredients);

        return json_encode([$ingredients, mb_strtolower((string) $fill->dosage_form)], JSON_THROW_ON_ERROR);
    }

    private function createFor(PhrPatient $patient, PhrMedicationFill $fill): PhrMedication
    {
        [$name, $dose, $unit] = $this->nameAndDose($fill);

        // The identity names the fill. A fill whose drug changed can create a second
        // entry (the first may be soft-deleted but still holds its slot), so it is suffixed.
        $externalId = 'fill:'.$fill->id;
        if (PhrMedication::withTrashed()->where('patient_id', $patient->id)->where('import_source', self::IMPORT_SOURCE)->where('external_id', $externalId)->exists()) {
            $externalId .= ':'.Str::lower(Str::random(6));
        }

        return PhrMedication::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            // Provenance points back at the fill that caused this entry.
            'import_source' => self::IMPORT_SOURCE,
            'external_id' => $externalId,
            'source_document_id' => $fill->source_document_id,
            'review_status' => PhrReviewStatus::PENDING,
            'name' => mb_substr($name, 0, 255),
            'rxnorm_code' => $fill->rxcui,
            'dose' => $dose === '' ? null : mb_substr($dose, 0, 100),
            'dose_unit' => $unit === '' ? null : mb_substr($unit, 0, 50),
            'route' => $fill->route === null ? null : mb_substr($fill->route, 0, 100),
            'started_on' => $fill->filled_on->toDateString(),
            'status' => 'active',
            'prescriber_name' => $fill->prescriber_name,
            'raw_text' => 'Added from prescription fill #'.$fill->id.($fill->drug_as_printed === null ? '' : ' ("'.$fill->drug_as_printed.'")'),
        ]);
    }

    /**
     * The name and dose a medication entry gets from a fill: the substances,
     * and the strength split into number and unit when it is a simple one.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function nameAndDose(PhrMedicationFill $fill): array
    {
        $substances = (array) $fill->substance_names;
        if ($substances === []) {
            return [trim((string) $fill->drug_as_printed), '', ''];
        }
        $strengths = array_map(
            static fn (string $strength): string => (string) preg_replace('#/1$#', '', $strength),
            array_values(array_filter((array) $fill->strengths, is_string(...))),
        );
        if (count($strengths) === 1 && preg_match('/\A(\d+(?:\.\d+)?)\s*([A-Za-z%]+)\z/', $strengths[0], $parts) === 1) {
            return [implode(' / ', $substances), $parts[1], $parts[2]];
        }

        return [implode(' / ', $substances), implode(' / ', $strengths), ''];
    }

    /**
     * An entry this linker made that nobody has touched, still waiting for review, and
     * that no live fill uses any more is withdrawn; anything a person reviewed or edited,
     * or other fills use, stays. Editing bumps updated_at (the linker never writes to the
     * entry after creating it), so a later updated_at means a person's changes are in it.
     */
    private function discardIfOrphan(PhrMedication $medication, PhrMedicationFill $fill): void
    {
        if ($medication->import_source !== self::IMPORT_SOURCE || $medication->review_status !== PhrReviewStatus::PENDING) {
            return;
        }
        if ($medication->updated_at !== null && $medication->created_at !== null && $medication->updated_at->gt($medication->created_at)) {
            return;
        }
        $stillUsed = PhrMedicationFill::query()
            ->where('medication_id', $medication->id)
            ->where('id', '!=', $fill->id)
            ->whereNull('retracted_at')
            ->exists();
        if (! $stillUsed) {
            $medication->delete();
        }
    }
}
