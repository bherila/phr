import type { RowAction } from '@/components/record-list/RowActionsMenu'
import { formatDate } from '@/lib/dateFormat'
import { pushPhrLocation } from '@/phr/shell/phrLocation'
import { patientPageUrl } from '@/phr/shell/phrRoutes'
import type { PhrPatient } from '@/phr/types'

import { sexAtBirthOption } from './PatientProfileFields'

/** The Data Hub, opened at one patient's card, where exports, backups and deletion live. */
export function dataHubPatientUrl(patientId: number): string {
  return `/phr/data-hub?patient_id=${patientId}`
}

/**
 * What someone may do to a patient beyond opening the record: edit the profile if they manage
 * it, and, for a record they own, reach its sharing, its exports and its deletion. The same list
 * backs the Patients cards and the patient's Summary.
 */
export function patientActions(patient: PhrPatient, { onEditProfile, onOpenAccess }: {
  onEditProfile: () => void
  onOpenAccess?: () => void
}): RowAction[] {
  if (!patient.can_manage) return []
  const actions: RowAction[] = [{ label: 'Edit profile', onSelect: onEditProfile }]
  if (patient.can_share) {
    actions.push(
      { label: 'Sharing and access', onSelect: onOpenAccess ?? (() => pushPhrLocation(patientPageUrl(patient.id, 'access'))) },
      { label: 'Export or back up', onSelect: () => pushPhrLocation(dataHubPatientUrl(patient.id)) },
      { label: 'Delete patient…', destructive: true, onSelect: () => pushPhrLocation(dataHubPatientUrl(patient.id)) },
    )
  }
  return actions
}

/**
 * Age in whole years, or in months under two, on `today`. The stored date is read as a calendar
 * date, never parsed as an instant, so no timezone moves the birthday.
 */
export function ageOn(birthDate: string, today: Date): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(birthDate)
  if (!match) return null
  const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
  let months = (today.getFullYear() - year) * 12 + (today.getMonth() + 1 - month)
  if (today.getDate() < day) months -= 1
  if (months < 0) return null
  return months < 24 ? `${months} mo` : `${Math.floor(months / 12)} y`
}

/** The facts that identify a patient, for a record's header: relationship, birth, sex, names. */
export function profileFacts(patient: PhrPatient, today: Date = new Date()): string[] {
  const facts: string[] = []
  if (patient.relationship) facts.push(patient.relationship)
  if (patient.birth_date) {
    const age = ageOn(patient.birth_date, today)
    facts.push(`born ${formatDate(patient.birth_date)}${age ? ` (${age})` : ''}`)
  }
  if (patient.sex_at_birth) facts.push(sexAtBirthOption(patient.sex_at_birth) || patient.sex_at_birth)
  const names = patient.real_names?.length ?? 0
  if (names > 0) facts.push(`${names} ${names === 1 ? 'name' : 'names'} on documents`)
  return facts
}
