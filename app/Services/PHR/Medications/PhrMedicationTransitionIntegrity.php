<?php

namespace App\Services\PHR\Medications;

use App\Models\PhrMedication;
use App\Models\PhrMedicationTransition;
use App\Models\PhrOfficeVisit;
use App\Support\PHR\PhrMedicationCategory;
use App\Support\PHR\PhrRecordLifecycle;
use Illuminate\Validation\ValidationException;

/**
 * Whether a medication transition (#414) says something coherent about its patient's
 * records: its action names the records it needs (also a database check), its visit and
 * medications are the patient's own standing records (the foreign keys hold the patient),
 * the home medication is on the home list (community, or a setting nobody stated) and the
 * order is one of the stay's: an inpatient order at admission, a discharge medication at
 * discharge. A transition is never inferred: every record it names was named by its writer.
 */
final class PhrMedicationTransitionIntegrity
{
    /**
     * What is wrong with the transition, by field; empty when it is coherent.
     *
     * @return array<string, string>
     */
    public function problems(PhrMedicationTransition $transition): array
    {
        $phase = $transition->phase;
        $action = $transition->action;
        $home = $transition->home_medication_id;
        $order = $transition->order_medication_id;
        $problems = [];

        if (! in_array($phase, PhrMedicationTransition::PHASES, true)) {
            $problems['phase'] = 'The phase must be admission or discharge.';
        }
        if (! in_array($action, PhrMedicationTransition::ACTIONS, true)) {
            $problems['action'] = 'The action must be continued, held, changed, stopped or new.';
        } elseif (in_array($action, PhrMedicationTransition::WITHOUT_ORDER, true)) {
            if ($home === null) {
                $problems['home_medication_id'] = "A {$action} transition names the home medication it {$action}.";
            }
            if ($order !== null) {
                $problems['order_medication_id'] = "A {$action} transition names no order: nothing carries the home medication on.";
            }
        } elseif ($action === PhrMedicationTransition::NEW) {
            if ($order === null) {
                $problems['order_medication_id'] = 'A new transition names the new order.';
            }
            if ($home !== null) {
                $problems['home_medication_id'] = 'A new transition names no home medication; one that replaces a home medication is changed.';
            }
        } else {
            if ($home === null) {
                $problems['home_medication_id'] = "A {$action} transition names the home medication.";
            }
            if ($order === null) {
                $problems['order_medication_id'] = "A {$action} transition names the order that carries the home medication on.";
            }
        }
        if ($home !== null && $home === $order) {
            $problems['order_medication_id'] = 'The order is a different record from the home medication.';
        }

        $patientId = $transition->patient_id;
        $visit = PhrOfficeVisit::query()->where('patient_id', $patientId)->find($transition->office_visit_id);
        if (! $visit instanceof PhrOfficeVisit || ! PhrRecordLifecycle::stands($visit)) {
            $problems['office_visit_id'] = 'The office visit is not a standing visit of this patient.';
        }
        if ($home !== null && ! isset($problems['home_medication_id'])) {
            $medication = $this->standingMedication($patientId, $home);
            if ($medication === null) {
                $problems['home_medication_id'] = 'The home medication is not a standing medication of this patient.';
            } elseif (! PhrMedicationCategory::isHomeList($medication->category)) {
                $problems['home_medication_id'] = 'The home medication is on the home list: its category is community, or none.';
            }
        }
        if ($order !== null && ! isset($problems['order_medication_id'])) {
            $medication = $this->standingMedication($patientId, $order);
            $expected = $phase === PhrMedicationTransition::DISCHARGE ? PhrMedicationCategory::DISCHARGE : PhrMedicationCategory::INPATIENT;
            if ($medication === null) {
                $problems['order_medication_id'] = 'The order is not a standing medication of this patient.';
            } elseif (in_array($phase, PhrMedicationTransition::PHASES, true) && $medication->category !== $expected) {
                $problems['order_medication_id'] = $phase === PhrMedicationTransition::DISCHARGE
                    ? 'A discharge transition names a discharge medication (category discharge).'
                    : 'An admission transition names an inpatient order (category inpatient).';
            }
        }

        return $problems;
    }

    /** Refuses an incoherent transition as the agent API's data fields. */
    public function assertCoherent(PhrMedicationTransition $transition): void
    {
        $problems = $this->problems($transition);
        if ($problems !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(static fn (string $field): string => 'data.'.$field, array_keys($problems)),
                array_values($problems),
            ));
        }
    }

    private function standingMedication(int $patientId, int $id): ?PhrMedication
    {
        $medication = PhrMedication::query()->where('patient_id', $patientId)->find($id);

        return $medication instanceof PhrMedication && PhrRecordLifecycle::stands($medication) ? $medication : null;
    }
}
