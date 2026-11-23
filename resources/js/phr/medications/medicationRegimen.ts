import { displayUnits } from '@/lib/measureUnits'
import type { PhrMedication } from '@/phr/types'

/** What a medication's regimen is read from: the medication list's record or the visit page's summary of one. */
export type MedicationRegimenFields = {
  [Field in 'dose' | 'dose_unit' | 'route' | 'frequency' | 'as_needed' | 'as_needed_reason' | 'rate']?: PhrMedication[Field] | undefined
}

/** "as needed for pain", for a medication taken only as needed. */
export function asNeededText(medication: MedicationRegimenFields): string | null {
  if (medication.as_needed !== true) return null
  return medication.as_needed_reason ? `as needed for ${medication.as_needed_reason}` : 'as needed'
}

/** "650 mg". */
export function medicationDoseText(medication: MedicationRegimenFields): string {
  return displayUnits([medication.dose, medication.dose_unit].filter(Boolean).join(' '))
}

/** "every 6 hours, as needed for pain", "at 75 mL/h". */
export function medicationScheduleText(medication: MedicationRegimenFields): string | null {
  return [medication.frequency, asNeededText(medication), medication.rate ? `at ${medication.rate}` : null].filter(Boolean).join(', ') || null
}

/** "650 mg · oral · every 6 hours, as needed for pain": dose, route and schedule, each where recorded. */
export function medicationRegimenText(medication: MedicationRegimenFields): string {
  return [medicationDoseText(medication), medication.route, medicationScheduleText(medication)].filter(Boolean).join(' · ')
}
