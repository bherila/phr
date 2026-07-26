import { useCallback, useEffect, useMemo, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage, readPatientIdFromQuery, setPatientIdInQuery } from '@/phr/shared'
import { type PhrPatient, PhrPatientListResponseSchema } from '@/phr/types'

interface UsePhrPatientsResult {
  patients: PhrPatient[]
  selectedPatientId: number | null
  selectedPatient: PhrPatient | null
  busy: boolean
  error: string | null
  setSelectedPatientId: (patientId: number | null) => void
  upsertPatient: (patient: PhrPatient) => void
  reloadPatients: () => Promise<void>
}

export function usePhrPatients(): UsePhrPatientsResult {
  const [patients, setPatients] = useState<PhrPatient[]>([])
  const [selectedPatientIdState, setSelectedPatientIdState] = useState<number | null>(() => readPatientIdFromQuery())
  const selectedPatientId = selectedPatientIdState
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const selectedPatient = useMemo(
    () => patients.find((patient) => patient.id === selectedPatientId) ?? null,
    [patients, selectedPatientId],
  )

  const setSelectedPatientId = useCallback((patientId: number | null) => {
    setSelectedPatientIdState(patientId)
    setPatientIdInQuery(patientId)
  }, [])

  const reloadPatients = useCallback(async (): Promise<void> => {
    setBusy(true)
    setError(null)

    try {
      const rawResponse: unknown = await fetchWrapper.get('/api/phr/patients')
      const response = PhrPatientListResponseSchema.parse(rawResponse)
      setPatients(response.patients)
      setSelectedPatientIdState((current) => {
        const fromQuery = readPatientIdFromQuery()
        const fallback = response.patients[0]?.id ?? null
        const next = fromQuery ?? current ?? fallback
        const isPresent = next !== null && response.patients.some((patient) => patient.id === next)
        return isPresent ? next : fallback
      })
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => {
    void reloadPatients()
  }, [reloadPatients])

  useEffect(() => {
    if (selectedPatientId !== null) {
      setPatientIdInQuery(selectedPatientId)
    }
  }, [selectedPatientId])

  const upsertPatient = useCallback((patient: PhrPatient): void => {
    setPatients((current) => {
      const next = current.filter((item) => item.id !== patient.id)
      next.push(patient)
      return next.sort((a, b) => (a.display_name ?? '').localeCompare(b.display_name ?? ''))
    })
    setSelectedPatientId(patient.id)
  }, [setSelectedPatientId])

  return {
    patients,
    selectedPatientId,
    selectedPatient,
    busy,
    error,
    setSelectedPatientId,
    upsertPatient,
    reloadPatients,
  }
}
