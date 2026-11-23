<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use App\Models\PhrMedicationTransition;
use Illuminate\Validation\Rule;

/**
 * A medication transition's fields (#414). These rules check each field on its own; what
 * the fields say together, and about the records they name, is checked against the stored
 * row as it would be saved (PhrMedicationTransitionIntegrity), so a partial update is held
 * to the same rules as a full write.
 */
final class MedicationTransitionDataRules implements ClinicalDataRules
{
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'office_visit_id' => [$required, 'integer', 'min:1'],
            'home_medication_id' => ['nullable', 'integer', 'min:1'],
            'order_medication_id' => ['nullable', 'integer', 'min:1'],
            'phase' => [$required, 'string', Rule::in(PhrMedicationTransition::PHASES)],
            'action' => [$required, 'string', Rule::in(PhrMedicationTransition::ACTIONS)],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'office_visit_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The stay (office visit) the transition happened in.'],
            'home_medication_id' => [
                'type' => ['integer', 'null'],
                'minimum' => 1,
                'description' => 'The home medication (category community, or none). Required for continued, held, changed and stopped; null for new.',
            ],
            'order_medication_id' => [
                'type' => ['integer', 'null'],
                'minimum' => 1,
                'description' => 'The order: an inpatient medication at admission, a discharge medication at discharge. Required for continued, changed and new; null for held and stopped.',
            ],
            'phase' => ['type' => 'string', 'enum' => PhrMedicationTransition::PHASES],
            'action' => [
                'type' => 'string',
                'enum' => PhrMedicationTransition::ACTIONS,
                'description' => 'continued: the order carries the home medication on unchanged; changed: the order replaces it (another drug, dose or schedule); held: paused with no order; stopped: ended with no order; new: an order with no home medication.',
            ],
            'note' => ClinicalJsonSchema::nullableString(maxLength: 10000),
        ], ['office_visit_id', 'phase', 'action']);
    }
}
