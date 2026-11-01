import { type DateFormat, getDisplayPreferences } from './displayPreferences'

export const DATE_FORMATS: ReadonlyArray<{ value: DateFormat, example: string }> = [
  { value: 'YYYY-MM-DD', example: '2026-11-01' },
  { value: 'MM/DD/YYYY', example: '11/01/2026' },
  { value: 'DD/MM/YYYY', example: '01/11/2026' },
  { value: 'MMM D, YYYY', example: 'Nov 1, 2026' },
]

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const CALENDAR_DATE = /^(\d{4})-(\d{2})-(\d{2})/

function writeDate(year: string, month: string, day: string): string {
  switch (getDisplayPreferences().date_format) {
    case 'MM/DD/YYYY': return `${month}/${day}/${year}`
    case 'DD/MM/YYYY': return `${day}/${month}/${year}`
    case 'MMM D, YYYY': return `${MONTHS[Number(month) - 1] ?? month} ${Number(day)}, ${year}`
    default: return `${year}-${month}-${day}`
  }
}

/**
 * A stored date, or the date a stored date-time starts with, in this person's format. Sliced and
 * never parsed, so no timezone can move it to another day. Text that is not a date comes back as
 * it is, and nothing becomes an empty string.
 */
export function formatDate(value: string | null | undefined): string {
  if (!value) return ''
  const match = CALENDAR_DATE.exec(value)
  return match ? writeDate(match[1]!, match[2]!, match[3]!) : value
}

/** A stored date-time as written: its date in this person's format, then its time if it has one. */
export function formatDateTime(value: string | null | undefined): string {
  const time = /^\d{4}-\d{2}-\d{2}[T ](\d{2}:\d{2})/.exec(value ?? '')?.[1]
  return time ? `${formatDate(value)} ${time}` : formatDate(value)
}

function parsedInstant(value: string | null | undefined): Date | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

/** A moment in time, such as when something was uploaded: the day it was where the viewer is. */
export function formatInstantDate(value: string | null | undefined): string {
  const date = parsedInstant(value)
  if (!date) return ''
  return writeDate(String(date.getFullYear()).padStart(4, '0'), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0'))
}

/** A moment in time with the time of day where the viewer is. */
export function formatInstant(value: string | null | undefined): string {
  const date = parsedInstant(value)
  if (!date) return ''
  return `${formatInstantDate(value)} ${new Intl.DateTimeFormat(undefined, { timeStyle: 'short' }).format(date)}`
}
