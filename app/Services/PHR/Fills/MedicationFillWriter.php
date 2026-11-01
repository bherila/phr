<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Services\PHR\Ndc\NdcResolution;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Validation\ValidationException;

/**
 * The part of writing a fill that the web app and the agent API share: turning
 * a client's data object into columns (resolving the drug from the NDC) and
 * attaching the saved fill to a medication.
 */
class MedicationFillWriter
{
    /** Columns a client may set directly. */
    private const array CLIENT_COLUMNS = [
        'drug_as_printed', 'patient_name_as_printed', 'filled_on', 'quantity', 'quantity_unit', 'days_supply',
        'rx_number', 'refills_remaining', 'directions', 'prescriber_name', 'paid_cents', 'retail_cents',
        'pharmacy_name', 'pharmacy_location', 'source_kind', 'source_locator',
    ];

    public function __construct(
        private readonly MedicationFillDrugResolver $drugs,
        private readonly MedicationFillMedicationLinker $linker,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{attributes: array<string, mixed>, resolution: ?NdcResolution, medication_id: ?int, drug_changed: bool}
     *
     * @throws ValidationException
     */
    public function prepare(PhrPatient $patient, array $data, ?PhrMedicationFill $existing, bool $strict): array
    {
        $drug = $this->drugs->resolve($data, $existing, $strict);
        $attributes = $drug['attributes'];
        foreach (self::CLIENT_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $data[$column];
            }
        }

        $medicationId = isset($data['medication_id']) ? (int) $data['medication_id'] : null;
        if ($medicationId !== null) {
            $exists = PhrMedication::query()
                ->where('patient_id', $patient->id)
                ->whereNull('retracted_at')
                ->where('review_status', '!=', PhrReviewStatus::REJECTED)
                ->whereKey($medicationId)
                ->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['medication_id' => 'This medication does not belong to the patient.']);
            }
            $attributes['medication_id'] = $medicationId;
        }

        return [
            'attributes' => $attributes,
            'resolution' => $drug['resolution'],
            'medication_id' => $medicationId,
            'drug_changed' => $drug['attributes'] !== [] || $this->printedNameChanged($data, $existing),
        ];
    }

    /**
     * After an edit changed the drug: keep the current medication while it still
     * fits, otherwise link or create. Never unlinks.
     *
     * @return array{medication: PhrMedication, created: bool}
     */
    public function reconcile(PhrPatient $patient, PhrMedicationFill $fill): array
    {
        return $this->linker->reconcile($patient, $fill);
    }

    /**
     * @return array{medication: PhrMedication, created: bool}
     */
    public function attach(PhrPatient $patient, PhrMedicationFill $fill, ?int $medicationId = null, ?int $previousMedicationId = null): array
    {
        return $this->linker->attach($patient, $fill, $medicationId, $previousMedicationId);
    }

    /** The fill was deleted or retracted: withdraw a medication only it kept alive. */
    public function release(PhrMedicationFill $fill): void
    {
        $this->linker->release($fill);
    }

    /**
     * A fill whose drug the server could not resolve is known only by what the label
     * said, so correcting that text changes the drug and the medication link must be
     * checked against it. A resolved fill's drug comes from its NDC; its printed text is
     * only a note.
     *
     * @param  array<string, mixed>  $data
     */
    private function printedNameChanged(array $data, ?PhrMedicationFill $existing): bool
    {
        if ($existing === null || $existing->ndc_status === PhrMedicationFill::NDC_RESOLVED || ! array_key_exists('drug_as_printed', $data)) {
            return false;
        }

        return trim((string) $data['drug_as_printed']) !== trim((string) $existing->drug_as_printed);
    }
}
