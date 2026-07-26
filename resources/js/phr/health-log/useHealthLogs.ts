import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { errorMessage } from '@/phr/shared'

import {
  createHealthLog,
  createHealthLogEntry,
  deleteHealthLog,
  deleteHealthLogEntry,
  listHealthLogEntries,
  listHealthLogs,
  updateHealthLog,
  updateHealthLogEntry,
} from './healthLogApi'
import type { HealthLog, HealthLogEntry } from './healthLogSchemas'

export interface HealthLogsState {
  healthLogs: HealthLog[]
  selectedHealthLog: HealthLog | null
  selectHealthLog: (healthLogId: number) => void
  entries: HealthLogEntry[]
  canManage: boolean
  logsBusy: boolean
  entriesBusy: boolean
  mutationKey: string | null
  error: string | null
  clearError: () => void
  addHealthLog: (payload: Record<string, unknown>) => Promise<boolean>
  editHealthLog: (healthLogId: number, payload: Record<string, unknown>) => Promise<boolean>
  removeHealthLog: (healthLogId: number) => Promise<boolean>
  addEntry: (payload: Record<string, unknown>) => Promise<boolean>
  editEntry: (entryId: number, payload: Record<string, unknown>) => Promise<boolean>
  removeEntry: (entryId: number) => Promise<boolean>
}

export function useHealthLogs(patientId: number): HealthLogsState {
  const [healthLogs, setHealthLogs] = useState<HealthLog[]>([])
  const [selectedHealthLogId, setSelectedHealthLogId] = useState<number | null>(null)
  const [entries, setEntries] = useState<HealthLogEntry[]>([])
  const [canManage, setCanManage] = useState(false)
  const [logsBusy, setLogsBusy] = useState(true)
  const [entriesBusy, setEntriesBusy] = useState(false)
  const [mutationKey, setMutationKey] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const entriesRequestSequenceRef = useRef(0)

  const loadHealthLogs = useCallback(async (
    preferredHealthLogId?: number,
    resetPatientContext = false,
  ): Promise<void> => {
    if (resetPatientContext) {
      setHealthLogs([])
      setEntries([])
      setSelectedHealthLogId(null)
    }
    setLogsBusy(true)
    setError(null)
    try {
      const result = await listHealthLogs(patientId)
      setHealthLogs(result.healthLogs)
      setCanManage(result.canManage)
      setSelectedHealthLogId((current) => {
        const preferred = preferredHealthLogId ?? current
        if (preferred !== null && preferred !== undefined && result.healthLogs.some((log) => log.id === preferred)) {
          return preferred
        }
        return result.healthLogs[0]?.id ?? null
      })
    } catch (caught) {
      setError(errorMessage(caught))
      setHealthLogs([])
      setSelectedHealthLogId(null)
    } finally {
      setLogsBusy(false)
    }
  }, [patientId])

  useEffect(() => {
    void loadHealthLogs(undefined, true)
  }, [loadHealthLogs])

  const loadEntries = useCallback(async (healthLogId: number): Promise<void> => {
    const requestSequence = ++entriesRequestSequenceRef.current
    setEntries([])
    setEntriesBusy(true)
    setError(null)
    try {
      const result = await listHealthLogEntries(patientId, healthLogId)
      if (entriesRequestSequenceRef.current === requestSequence) {
        setEntries(result.entries)
        setCanManage((current) => current && result.canManage)
      }
    } catch (caught) {
      if (entriesRequestSequenceRef.current === requestSequence) {
        setError(errorMessage(caught))
        setEntries([])
      }
    } finally {
      if (entriesRequestSequenceRef.current === requestSequence) {
        setEntriesBusy(false)
      }
    }
  }, [patientId])

  useEffect(() => {
    if (selectedHealthLogId === null) {
      entriesRequestSequenceRef.current += 1
      return
    }

    void loadEntries(selectedHealthLogId)

    return () => {
      entriesRequestSequenceRef.current += 1
    }
  }, [loadEntries, selectedHealthLogId])

  const selectedHealthLog = useMemo(
    () => healthLogs.find((healthLog) => healthLog.id === selectedHealthLogId) ?? null,
    [healthLogs, selectedHealthLogId],
  )

  async function mutate(key: string, action: () => Promise<void>): Promise<boolean> {
    setMutationKey(key)
    setError(null)
    try {
      await action()
      return true
    } catch (caught) {
      setError(errorMessage(caught))
      return false
    } finally {
      setMutationKey(null)
    }
  }

  async function addHealthLog(payload: Record<string, unknown>): Promise<boolean> {
    return mutate('add-log', async () => {
      const healthLog = await createHealthLog(patientId, payload)
      setHealthLogs((current) => [healthLog, ...current])
      setSelectedHealthLogId(healthLog.id)
    })
  }

  async function editHealthLog(healthLogId: number, payload: Record<string, unknown>): Promise<boolean> {
    return mutate(`edit-log:${healthLogId}`, async () => {
      const healthLog = await updateHealthLog(patientId, healthLogId, payload)
      setHealthLogs((current) => current.map((item) => item.id === healthLog.id ? healthLog : item))
    })
  }

  async function removeHealthLog(healthLogId: number): Promise<boolean> {
    return mutate(`delete-log:${healthLogId}`, async () => {
      await deleteHealthLog(patientId, healthLogId)
      setHealthLogs((current) => current.filter((item) => item.id !== healthLogId))
      if (selectedHealthLogId === healthLogId) {
        const remaining = healthLogs.filter((item) => item.id !== healthLogId)
        setSelectedHealthLogId(remaining[0]?.id ?? null)
      }
    })
  }

  async function addEntry(payload: Record<string, unknown>): Promise<boolean> {
    if (selectedHealthLogId === null) {
      return false
    }

    return mutate('add-entry', async () => {
      const entry = await createHealthLogEntry(patientId, selectedHealthLogId, payload)
      setEntries((current) => [entry, ...current])
      await loadHealthLogs(selectedHealthLogId)
    })
  }

  async function editEntry(entryId: number, payload: Record<string, unknown>): Promise<boolean> {
    if (selectedHealthLogId === null) {
      return false
    }

    return mutate(`edit-entry:${entryId}`, async () => {
      const entry = await updateHealthLogEntry(patientId, selectedHealthLogId, entryId, payload)
      setEntries((current) => current.map((item) => item.id === entry.id ? entry : item))
      await loadHealthLogs(selectedHealthLogId)
    })
  }

  async function removeEntry(entryId: number): Promise<boolean> {
    if (selectedHealthLogId === null) {
      return false
    }

    return mutate(`delete-entry:${entryId}`, async () => {
      await deleteHealthLogEntry(patientId, selectedHealthLogId, entryId)
      setEntries((current) => current.filter((item) => item.id !== entryId))
      await loadHealthLogs(selectedHealthLogId)
    })
  }

  return {
    healthLogs,
    selectedHealthLog,
    selectHealthLog: setSelectedHealthLogId,
    entries,
    canManage,
    logsBusy,
    entriesBusy,
    mutationKey,
    error,
    clearError: () => setError(null),
    addHealthLog,
    editHealthLog,
    removeHealthLog,
    addEntry,
    editEntry,
    removeEntry,
  }
}
