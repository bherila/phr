import { useCallback } from 'react'

import { type MillerDockPrefsSnapshot, useMillerDockPrefs,type UseMillerDockPrefsResult } from '@/components/ui/miller'

import { PHR_MODULE_IDS_SET, type PhrModuleId } from './phrModuleRegistry'

interface PhrDockPrefs {
  pinned: PhrModuleId[]
  recent: PhrModuleId[]
}

const EMPTY: PhrDockPrefs = { pinned: [], recent: [] }

function storageKeyForPatient(patientId: number): string {
  return `phr-dock-prefs-patient-${patientId}`
}

function isPhrModuleId(value: unknown): value is PhrModuleId {
  return typeof value === 'string' && PHR_MODULE_IDS_SET.has(value)
}

function sanitizeModuleIds(value: unknown): PhrModuleId[] {
  if (!Array.isArray(value)) {
    return []
  }

  return value.filter(isPhrModuleId)
}

function readStoredPrefs(storageKey: string | null): PhrDockPrefs {
  if (storageKey === null || typeof window === 'undefined') {
    return EMPTY
  }

  try {
    const raw = window.localStorage.getItem(storageKey)
    if (!raw) {
      return EMPTY
    }

    const parsed = JSON.parse(raw) as Partial<PhrDockPrefs> | null
    if (!parsed) {
      return EMPTY
    }

    return {
      pinned: sanitizeModuleIds(parsed.pinned),
      recent: sanitizeModuleIds(parsed.recent),
    }
  } catch {
    return EMPTY
  }
}

function writeStoredPrefs(storageKey: string | null, prefs: PhrDockPrefs): void {
  if (storageKey === null || typeof window === 'undefined') {
    return
  }

  try {
    window.localStorage.setItem(storageKey, JSON.stringify(prefs))
  } catch {
    /* Preferences are best-effort; ignore quota and serialization failures. */
  }
}

export type UsePhrDockPrefsResult = UseMillerDockPrefsResult<PhrModuleId>

export function usePhrDockPrefs(patientId: number | undefined): UsePhrDockPrefsResult {
  const storageKey = patientId === undefined ? null : storageKeyForPatient(patientId)

  const readPrefs = useCallback((): MillerDockPrefsSnapshot<PhrModuleId> => readStoredPrefs(storageKey), [storageKey])

  const writePrefs = useCallback((nextPrefs: MillerDockPrefsSnapshot<PhrModuleId>): void => {
    writeStoredPrefs(storageKey, nextPrefs)
  }, [storageKey])

  return useMillerDockPrefs({
    canCommit: storageKey !== null,
    readPrefs,
    writePrefs,
  })
}
