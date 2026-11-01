import { useSyncExternalStore } from 'react'

import { fetchWrapper } from '@/fetchWrapper'

import { displayPreferencesFromPage } from './appShell'

export type FillColumn = 'no_insurance' | 'prescriber' | 'pharmacy'

export interface DisplayPreferences {
  hide_medication_list: boolean
  hidden_fill_columns: FillColumn[]
}

const FILL_COLUMNS: readonly FillColumn[] = ['no_insurance', 'prescriber', 'pharmacy']

function normalize(raw: unknown): DisplayPreferences {
  const value = (typeof raw === 'object' && raw !== null ? raw : {}) as Record<string, unknown>
  const hidden = Array.isArray(value.hidden_fill_columns) ? value.hidden_fill_columns : []
  return {
    hide_medication_list: value.hide_medication_list === true,
    hidden_fill_columns: FILL_COLUMNS.filter((column) => hidden.includes(column)),
  }
}

let current: DisplayPreferences | null = null
const listeners = new Set<() => void>()

function read(): DisplayPreferences {
  current ??= normalize(displayPreferencesFromPage())
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

/** This person's display settings, from the page's initial data, following changes made here. */
export function useDisplayPreferences(): DisplayPreferences {
  return useSyncExternalStore(subscribe, read, read)
}

/** Applies at once and saves on the logged-in user; rejects, and puts it back, if the save fails. */
export async function saveDisplayPreferences(change: Partial<DisplayPreferences>): Promise<void> {
  const before = read()
  replace({ ...before, ...change })
  try {
    await fetchWrapper.put('/api/user/display-preferences', change)
  } catch (caught) {
    replace(before)
    throw caught
  }
}

/** Forgets what was read, so the next read starts from the page again. */
export function resetDisplayPreferences(): void {
  current = null
}
