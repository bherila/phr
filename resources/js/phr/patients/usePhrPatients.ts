import { createContext, createElement, type ReactElement, type ReactNode, use, useEffect, useState, useSyncExternalStore } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'
import { type PhrPatient, PhrPatientListResponseSchema } from '@/phr/types'

interface PatientListState {
  patients: PhrPatient[]
  loaded: boolean
  busy: boolean
  error: string | null
}

interface PatientListStore {
  subscribe: (listener: () => void) => () => void
  snapshot: () => PatientListState
  ensureLoaded: () => Promise<void>
  reloadPatients: (options?: { afterMutation?: boolean }) => Promise<void>
  upsertPatient: (patient: PhrPatient) => void
  removePatient: (patientId: number) => void
}

/** Read only when a PHR-scoped store is created; never retain patient data globally. */
function bootstrapPatients(): PhrPatient[] | undefined {
  if (typeof document === 'undefined') return undefined
  const text = document.getElementById('app-initial-data')?.textContent
  if (!text) return undefined
  try {
    const raw: unknown = JSON.parse(text)
    if (typeof raw !== 'object' || raw === null || !('phr' in raw)) return undefined
    const phr = raw.phr
    if (typeof phr !== 'object' || phr === null || !('patient_list' in phr)) return undefined
    const parsed = PhrPatientListResponseSchema.safeParse(phr.patient_list)
    return parsed.success ? parsed.data.patients : undefined
  } catch {
    return undefined
  }
}

function createPatientListStore(): PatientListStore {
  const seed = bootstrapPatients()
  let state: PatientListState = { patients: seed ?? [], loaded: seed !== undefined, busy: seed === undefined, error: null }
  const listeners = new Set<() => void>()
  let inFlight: Promise<void> | null = null
  // Only mutations during a request need an overlay. It is discarded once that
  // request settles, so deleted profiles do not become a growing lifetime cache.
  const pendingMutations = new Map<number, PhrPatient | null>()

  function publish(next: PatientListState): void {
    state = next
    for (const listener of listeners) listener()
  }

  function reloadPatients(options: { afterMutation?: boolean } = {}): Promise<void> {
    if (inFlight !== null) {
      // Share/restore writes have no complete list DTO to merge. Their refresh
      // must begin after any older request, rather than reusing its snapshot.
      return options.afterMutation ? inFlight.then(() => reloadPatients()) : inFlight
    }
    publish({ ...state, busy: true, error: null })
    inFlight = Promise.resolve().then(async () => {
      try {
        const raw: unknown = await fetchWrapper.get('/api/phr/patients')
        const response = PhrPatientListResponseSchema.parse(raw)
        const patients = response.patients.filter((patient) => !pendingMutations.has(patient.id))
        for (const patient of pendingMutations.values()) {
          if (patient !== null) patients.push(patient)
        }
        publish({ patients, loaded: true, busy: false, error: null })
      } catch (caught) {
        publish({ ...state, busy: false, error: errorMessage(caught) })
      } finally {
        pendingMutations.clear()
        inFlight = null
      }
    })
    return inFlight
  }

  return {
    subscribe(listener) {
      listeners.add(listener)
      return () => { listeners.delete(listener) }
    },
    snapshot: () => state,
    ensureLoaded: () => state.loaded ? Promise.resolve() : reloadPatients(),
    reloadPatients,
    upsertPatient(patient) {
      if (inFlight !== null) pendingMutations.set(patient.id, patient)
      const patients = state.patients.filter((item) => item.id !== patient.id)
      patients.push(patient)
      // A mutation supplies one row, not a successfully loaded whole list.
      publish({ ...state, patients })
    },
    removePatient(patientId) {
      if (inFlight !== null) pendingMutations.set(patientId, null)
      publish({ ...state, patients: state.patients.filter((patient) => patient.id !== patientId) })
    },
  }
}

const PatientListContext = createContext<PatientListStore | null>(null)

/** One provider lives for the shell, above every patient picker and page. */
export function PhrPatientsProvider({ children }: { children: ReactNode }): ReactElement {
  const [store] = useState(createPatientListStore)
  useEffect(() => { void store.ensureLoaded() }, [store])
  return createElement(PatientListContext.Provider, { value: store }, children)
}

/** Standalone pages retain their own scoped fallback for isolated mounts/tests. */
export function usePhrPatients() {
  const sharedStore = use(PatientListContext)
  const [store] = useState(() => sharedStore ?? createPatientListStore())
  const state = useSyncExternalStore(store.subscribe, store.snapshot, store.snapshot)
  useEffect(() => {
    if (sharedStore === null) void store.ensureLoaded()
  }, [sharedStore, store])
  return {
    ...state,
    reloadPatients: store.reloadPatients,
    upsertPatient: store.upsertPatient,
    removePatient: store.removePatient,
  }
}


/** Mutation-only screens update the shell without starting a separate list request. */
export function usePhrPatientListActions(): Pick<PatientListStore, 'upsertPatient' | 'removePatient' | 'reloadPatients'> | null {
  return use(PatientListContext)
}
