<?php

namespace App\Contracts\PHR;

use App\Models\PhrPatient;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource-specific step in the generic agent update path, for a record type
 * whose columns are not all client-supplied (prescription fills resolve their
 * drug from an NDC). Both hooks run inside the update's transaction.
 */
interface ClinicalWriteExtension
{
    /**
     * Turns the client's data object into the attributes to apply, or refuses
     * it with a ValidationException.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function prepareUpdate(PhrPatient $patient, Model $record, array $attributes): array;

    /** Runs after an effective change was saved, before the new version is computed. */
    public function afterUpdate(PhrPatient $patient, Model $record): void;
}
