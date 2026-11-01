<?php

namespace App\Services\PHR\Fills;

use App\Contracts\PHR\ClinicalWriteExtension;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use Illuminate\Database\Eloquent\Model;

/** Re-resolves a fill's drug when an agent update changes its NDC, and keeps its medication link in step. */
final class MedicationFillWriteExtension implements ClinicalWriteExtension
{
    private bool $drugChanged = false;

    private ?int $explicitMedication = null;

    private ?int $previousMedication = null;

    public function __construct(private readonly MedicationFillWriter $writer) {}

    public function prepareUpdate(PhrPatient $patient, Model $record, array $attributes): array
    {
        /** @var PhrMedicationFill $record */
        $prepared = $this->writer->prepare($patient, $attributes, $record, false);
        $this->drugChanged = $prepared['drug_changed'];
        $this->explicitMedication = $prepared['medication_id'];
        $this->previousMedication = $record->medication_id;

        return $prepared['attributes'];
    }

    public function afterUpdate(PhrPatient $patient, Model $record): void
    {
        /** @var PhrMedicationFill $record */
        if ($this->explicitMedication !== null) {
            // Every link change goes through the linker so the medication it leaves is tidied.
            $this->writer->attach($patient, $record, $this->explicitMedication, $this->previousMedication);
        } elseif ($this->drugChanged) {
            $this->writer->reconcile($patient, $record);
        }
    }

    public function afterRetract(PhrPatient $patient, Model $record): void
    {
        /** @var PhrMedicationFill $record */
        $this->writer->release($record);
    }
}
