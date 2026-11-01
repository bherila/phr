import { useSyncExternalStore } from 'react'

import { fetchWrapper } from '@/fetchWrapper'

import { displayPreferencesFromPage } from './appShell'

export type FillColumn = 'no_insurance' | 'prescriber' | 'pharmacy'

export type DateFormat = 'YYYY-MM-DD' | 'MM/DD/YYYY' | 'DD/MM/YYYY' | 'MMM D, YYYY'

export interface DisplayPreferences {
  date_format: DateFormat
  hide_medication_list: boolean
  separate_columns: boolean
  hidden_fill_columns: FillColumn[]
}

const FILL_COLUMNS: readonly FillColumn[] = ['no_insurance', 'prescriber', 'pharmacy']
const DATE_FORMAT_VALUES: readonly DateFormat[] = ['YYYY-MM-DD', 'MM/DD/YYYY', 'DD/MM/YYYY', 'MMM D, YYYY']

function normalize(raw: unknown): DisplayPreferences {
  const value = (typeof raw === 'object' && raw !== null ? raw : {}) as Record<string, unknown>
  const hidden = Array.isArray(value.hidden_fill_columns) ? value.hidden_fill_columns : []
  return {
    date_format: DATE_FORMAT_VALUES.includes(value.date_format as DateFormat) ? value.date_format as DateFormat : 'YYYY-MM-DD',
    hide_medication_list: value.hide_medication_list === true,
    separate_columns: value.separate_columns === true,
    hidden_fill_columns: FILL_COLUMNS.filter((column) => hidden.includes(column)),
  }
}

let current: DisplayPreferences | null = null
const listeners = new Set<() => void>()

function read(): DisplayPreferences {
  current ??= normalize(typeof document === 'undefined' ? null : displayPreferencesFromPage())
  return current
}

function replace(next: DisplayPreferences): void {
  current = next
  for (const listener of listeners) listener()
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** The settings as they are now, for code outside a component, such as formatting a date. */
export function getDisplayPreferences(): DisplayPreferences {
  return read()
}

/** This person's display settings, from the page's initial data, following changes made here. */
export function useDisplayPreferences(): DisplayPreferences {
  return useSyncExternalStore(subscribe, read, read)
}

let saving: Promise<unknown> = Promise.resolve()
let saveCount = 0
/** Which save last changed each setting, so a failure cannot undo a newer choice. */
const lastSaveOf = new Map<string, number>()

/**
 * Applies at once and saves on the logged-in user, one request at a time so quick changes reach
 * the server in order. If a save fails it rejects and puts back what it changed, unless a newer
 * save has changed that setting since.
 */
export async function saveDisplayPreferences(change: Partial<DisplayPreferences>): Promise<void> {
  const before = read()
  const save = ++saveCount
  const keys = Object.keys(change)
  for (const key of keys) lastSaveOf.set(key, save)
  replace({ ...before, ...change })
  const request = saving.then(() => fetchWrapper.put('/api/user/display-preferences', change))
  saving = request.catch(() => undefined)
  try {
    await request
  } catch (caught) {
    const restored = keys.filter((key) => lastSaveOf.get(key) === save).map((key) => [key, before[key as keyof DisplayPreferences]])
    replace({ ...read(), ...Object.fromEntries(restored) })
    throw caught
  }
}

/** Forgets what was read, so the next read starts from the page again. */
export function resetDisplayPreferences(): void {
  current = null
  saving = Promise.resolve()
  lastSaveOf.clear()
}
