import { displayUnits } from '@/lib/measureUnits'
import type { PhrImmunization } from '@/phr/types'

type DoseFields = Pick<PhrImmunization, 'dose_amount' | 'dose_unit' | 'dose_number' | 'series_doses'>

function present(value: string | null | undefined): string | null {
  const trimmed = value?.trim() ?? ''
  return trimmed.length > 0 ? trimmed : null
}

/** The administered amount with its unit (e.g. "0.50 mL"), or null when no amount is recorded. */
export function doseAmountLabel(immunization: DoseFields): string | null {
  const amount = present(immunization.dose_amount)
  if (amount === null) return null

  const unit = present(immunization.dose_unit)
  return displayUnits(unit === null ? amount : `${amount} ${unit}`)
}

/** The position in the series (e.g. "2 / 3"), or null when no dose number is recorded. */
export function seriesPositionLabel(immunization: DoseFields): string | null {
  if (immunization.dose_number === null) return null

  return immunization.series_doses === null ? `${immunization.dose_number}` : `${immunization.dose_number} / ${immunization.series_doses}`
}

const ADMINISTRATION_STATUS_LABELS: Record<string, string> = {
  given: 'Given',
  historical: 'Given (historical)',
  partial: 'Partially administered',
  deleted: 'Deleted',
  deferred: 'Deferred',
  refused: 'Refused',
  incomplete: 'Incomplete',
  invalid: 'Invalid',
  unknown: 'Unknown',
}

/** A readable administration status, or null when none is recorded. */
export function administrationStatusLabel(status: string | null | undefined): string | null {
  const value = present(status)
  if (value === null) return null

  return ADMINISTRATION_STATUS_LABELS[value] ?? value
}
