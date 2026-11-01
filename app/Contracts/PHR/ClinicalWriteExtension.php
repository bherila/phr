<?php

namespace App\Contracts\PHR;

use App\Models\PhrPatient;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource-specific step in the generic agent update path, for a record type
 * whose columns are not all client-supplied (prescription fills resolve their
 * drug from an NDC). prepareUpdate runs before the update's transaction opens, so
 * it may call a network service without holding a row lock; the other hooks run
 * inside the transaction.
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

    /** Runs after the record was retracted by the integration that wrote it. */
    public function afterRetract(PhrPatient $patient, Model $record): void;
}
