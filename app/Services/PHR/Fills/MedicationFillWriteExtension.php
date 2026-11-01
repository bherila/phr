<?php

namespace App\Services\PHR\Fills;

use App\Contracts\PHR\ClinicalWriteExtension;
use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use Illuminate\Database\Eloquent\Model;

/** Re-resolves a fill's drug when an agent update changes its NDC, and re-links its medication. */
final class MedicationFillWriteExtension implements ClinicalWriteExtension
{
    private bool $drugChanged = false;

    private bool $explicitMedication = false;

    public function __construct(private readonly MedicationFillWriter $writer) {}

    public function prepareUpdate(PhrPatient $patient, Model $record, array $attributes): array
    {
        /** @var PhrMedicationFill $record */
        $prepared = $this->writer->prepare($patient, $attributes, $record, false);
        $this->drugChanged = $prepared['drug_changed'];
        $this->explicitMedication = $prepared['medication_id'] !== null;

        return $prepared['attributes'];
    }

    public function afterUpdate(PhrPatient $patient, Model $record): void
    {
        /** @var PhrMedicationFill $record */
        if ($this->drugChanged && ! $this->explicitMedication) {
            $this->writer->reconcile($patient, $record);
        }
    }
}
